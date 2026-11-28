<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Dumper\Domain;

use Symfony\Component\DependencyInjection\Compiler\AbstractRecursivePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects the references between the services of different domains.
 *
 * It follows the references the same way as CheckServiceDomainsPass, but records each reference
 * that crosses the border of a domain instead of failing on the ones that aren't allowed.
 *
 * @internal
 */
final class DomainGraphCollector extends AbstractRecursivePass
{
    protected bool $skipScalars = true;

    /**
     * @var array<string, array{array<string, string>, bool, array<string, string>}>
     */
    private array $domains = [];
    private array $visited = [];

    /**
     * @var array<string, array<string, array<string, ServiceReference>>>
     */
    private array $references = [];

    public function __construct()
    {
        $this->enableExpressionProcessing();
    }

    public function collect(ContainerBuilder $container): DomainGraph
    {
        $this->process($container);

        $domains = [];
        foreach ($this->domains as [$memberships]) {
            $domains += $memberships;
        }
        sort($domains);

        $dependencies = [];
        ksort($this->references);

        foreach ($this->references as $consumer => $providers) {
            ksort($providers);

            foreach ($providers as $provider => $references) {
                $dependencies[] = new DomainDependency($consumer, $provider, array_values($references));
            }
        }

        $this->domains = $this->references = [];

        return new DomainGraph($domains, $dependencies);
    }

    public function process(ContainerBuilder $container): void
    {
        $this->domains = $this->references = [];

        foreach ($container->findTaggedServiceIds('container.domain') as $id => $tags) {
            $domains = $restricted = [];
            $exposed = false;

            foreach ($tags as $tag) {
                if (!\is_string($domain = $tag['domain'] ?? null) || '' === $domain) {
                    continue;
                }

                $domains[$domain] = $domain;
                $exposed = $exposed || true === ($tag['exposed'] ?? false);
                if (true === ($tag['restricted'] ?? false)) {
                    $restricted[$domain] = $domain;
                }
            }

            if ($domains) {
                $this->domains[$id] = [$domains, $exposed, $restricted];
            }
        }

        $this->container = $container;

        try {
            foreach ($this->domains as $id => $_) {
                $this->currentId = $id;
                $this->visited = [];
                parent::processValue($container->getDefinition($id));
            }

            // A locator that was created for a service, or for one of its methods, is collected as this service
            foreach ($container->findTaggedServiceIds('container.service_locator_context') as $id => $tags) {
                $consumer = $tags[0]['id'] ?? '';

                if (!isset($this->domains[$consumer])) {
                    $consumer = strstr($consumer, '::', true) ?: '';
                }

                if (isset($this->domains[$consumer])) {
                    $this->currentId = $consumer;
                    $this->visited = [];
                    $this->processValue(new Reference($id));
                }
            }
        } finally {
            $this->container = null;
            $this->visited = [];
        }
    }

    protected function processValue(mixed $value, bool $isRoot = false): mixed
    {
        if (!$value instanceof Reference) {
            return parent::processValue($value, $isRoot);
        }

        if (null === $id = $this->getDefinitionId((string) $value)) {
            return $value;
        }

        $definition = $this->container->getDefinition($id);

        // Locators, lazy proxies and anonymous services are hidden services that are generated for the service that references them
        if (null === $definition->innerServiceId && '.' === $id[0] && !isset($this->domains[$id])) {
            if (!isset($this->visited[$id])) {
                $this->visited[$id] = true;

                if ($definition->hasTag('container.service_locator_context')) {
                    $this->processValue($definition->getFactory()[0] ?? null);
                } else {
                    parent::processValue($definition);
                }
            }

            return $value;
        }

        // Decorating a service does not change who can reference it
        while (null !== $definition->innerServiceId && null !== $inner = $this->getDefinitionId($definition->innerServiceId)) {
            $definition = $this->container->getDefinition($id = $inner);
        }

        if (!isset($this->domains[$id])) {
            return $value;
        }

        [$domains, $exposed] = $this->domains[$id];
        [$consumerDomains, , $restricted] = $this->domains[$this->currentId];

        if (array_intersect($consumerDomains, $domains)) {
            return $value;
        }

        $reference = new ServiceReference($this->currentId, (string) $value, $exposed && !array_diff($restricted, $domains));

        foreach ($consumerDomains as $consumer) {
            foreach ($domains as $provider) {
                $this->references[$consumer][$provider][$this->currentId.'|'.$value] = $reference;
            }
        }

        return $value;
    }

    private function getDefinitionId(string $id): ?string
    {
        $seen = [];

        while ($this->container->hasAlias($id)) {
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $id = (string) $this->container->getAlias($id);
        }

        return $this->container->hasDefinition($id) ? $id : null;
    }
}
