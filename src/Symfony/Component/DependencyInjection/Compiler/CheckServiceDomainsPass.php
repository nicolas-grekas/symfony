<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Checks that the services that belong to a domain only reference services they can access.
 *
 * A service can reference the services of its own domains, the exposed services of other domains and the services that belong to no domain.
 * A restricted service can only reference the services of the domain it is restricted to.
 * A service that belongs to no domain is not checked.
 *
 * @internal
 */
final class CheckServiceDomainsPass extends AbstractRecursivePass
{
    protected bool $skipScalars = true;

    /**
     * @var array<string, array{array<string, string>, bool, array<string, string>}>
     */
    private array $domains = [];
    private array $visited = [];

    public function __construct()
    {
        $this->enableExpressionProcessing();
    }

    public function process(ContainerBuilder $container): void
    {
        $this->domains = [];

        foreach ($container->findTaggedServiceIds('container.domain') as $id => $tags) {
            $domains = $restricted = [];
            $exposed = false;

            foreach ($tags as $tag) {
                if (!\is_string($domain = $tag['domain'] ?? null) || '' === $domain) {
                    throw new InvalidArgumentException(\sprintf('The "container.domain" tag on service "%s" requires a non-empty "domain" attribute.', $id));
                }
                foreach (['exposed', 'restricted'] as $option) {
                    if (!\is_bool($tag[$option] ?? false)) {
                        throw new InvalidArgumentException(\sprintf('The "%s" attribute of the "container.domain" tag on service "%s" must be a boolean.', $option, $id));
                    }
                }

                $domains[$domain] = $domain;
                $exposed = $exposed || ($tag['exposed'] ?? false);
                if ($tag['restricted'] ?? false) {
                    $restricted[$domain] = $domain;
                }
            }

            $this->domains[$id] = [$domains, $exposed, $restricted];
        }

        if (!$this->domains) {
            return;
        }

        $this->container = $container;

        try {
            foreach ($this->domains as $id => $_) {
                $this->currentId = $id;
                $this->visited = [];
                parent::processValue($container->getDefinition($id));
            }

            // A locator that was created for a service, or for one of its methods, is checked as this service
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
            $this->domains = $this->visited = [];
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

        // Locators, lazy proxies and anonymous services are hidden services that are generated for the service that references them.
        // When they belong to no domain, their references are checked as references of that service.
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

        [$domains, $exposed] = $this->domains[$id] ?? [[], false];
        [$consumerDomains, , $restricted] = $this->domains[$this->currentId];

        if ($restricted = array_diff($restricted, $domains)) {
            $error = \sprintf('"%s" is restricted to the "%s" domain%s. Add "%s" to %s to allow it.', $this->currentId, implode('", "', $restricted), 1 < \count($restricted) ? 's' : '', $value, 1 < \count($restricted) ? 'these domains' : 'this domain');
        } elseif ($domains && !$exposed && !array_intersect($consumerDomains, $domains)) {
            $error = \sprintf('"%s" is internal to the "%s" domain%s. Expose it or add "%s" to %s.', $value, implode('", "', $domains), 1 < \count($domains) ? 's' : '', $this->currentId, 1 < \count($domains) ? 'one of these domains' : 'this domain');
        } else {
            return $value;
        }

        $this->container->getDefinition($this->currentId)->addError(\sprintf('The service "%s" cannot reference "%s": ', $this->currentId, $value).$error);

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
