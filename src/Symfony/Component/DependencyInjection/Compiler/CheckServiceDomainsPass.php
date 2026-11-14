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
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
final class CheckServiceDomainsPass extends AbstractRecursivePass
{
    protected bool $skipScalars = true;

    private ServiceDomain $domains;
    private array $source;
    private Definition $sourceDefinition;
    private array $visiting = [];
    private array $references = [];
    private bool $enabled;

    public function __construct()
    {
        $this->enableExpressionProcessing();
    }

    public function process(ContainerBuilder $container): void
    {
        $this->domains = new ServiceDomain($container);
        $services = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            $info = $this->domains->getDefinition($definition, $id);
            if ($info['domains'] || $info['allowed']) {
                $services[$id] = $info;
            }
        }
        foreach ($container->getAliases() as $id => $alias) {
            if ($alias->getDomains() || $alias->getAllowedDomains() || $services) {
                $info = $this->domains->get($id);
                if ($info['domains'] || $info['allowed']) {
                    $services[$id] = $info;
                    $alias->setDomains($info['domains'])->setExposed($info['exposed'])->setAllowedDomains($info['allowed']);
                }
            }
        }
        $this->enabled = (bool) $services;
        $this->references = [];
        $this->visiting = [];

        parent::process($container);

        if ($services || $this->references) {
            ksort($services);
            ksort($this->references);
            // Build parameters that contain arrays are kept in the runtime container.
            $container->setParameter('.container.domains', json_encode($services, \JSON_THROW_ON_ERROR));
            $container->setParameter('.container.domain_references', json_encode($this->references, \JSON_THROW_ON_ERROR));
        }
    }

    protected function processValue(mixed $value, bool $isRoot = false): mixed
    {
        if ($value instanceof Definition) {
            if ($isRoot) {
                if ($value->isAbstract() || $value->isSynthetic() || $this->isLocator($value)) {
                    return $value;
                }
                $this->sourceDefinition = $value;
                $this->source = $this->domains->getDefinition($value, $this->currentId);
            }

            return parent::processValue($value, $isRoot);
        }
        if (!$value instanceof Reference || !$this->container->hasDefinition((string) $value) && !$this->container->hasAlias((string) $value)) {
            return parent::processValue($value, $isRoot);
        }

        $id = (string) $value;
        $allowed = $value->getAllowedDomain();
        if (!$this->enabled && null === $allowed) {
            return $value;
        }
        $target = $this->domains->get($id);
        try {
            $definition = $this->container->findDefinition($id);
        } catch (ServiceNotFoundException) {
            return $value;
        }
        if (null === $allowed && $this->isLocator($definition)) {
            if (!isset($this->visiting[$id])) {
                $this->visiting[$id] = true;
                parent::processValue($definition);
                unset($this->visiting[$id]);
            }

            return $value;
        }
        $access = null;

        if (null !== $allowed) {
            if (!\in_array($allowed, $target['domains'], true)) {
                $this->sourceDefinition->addError(\sprintf('The service "%s" allows access to the "%s" domain through "%s", but this service does not belong to that domain.', $this->currentId, $allowed, $id));
                $access = 'forbidden';
            } else {
                $access = 'reference';
            }
        }
        if (null === $access && isset($this->sourceDefinition->innerServiceId) && $id === $this->sourceDefinition->innerServiceId) {
            $access = 'decoration';
        }
        if (null === $access) {
            foreach ($this->source['restricted'] as $domain) {
                if (!\in_array($domain, $target['domains'], true) && !\in_array($domain, $target['allowed'], true)) {
                    $this->sourceDefinition->addError(\sprintf('The service "%s" cannot reference "%s": this service is not available to the restricted "%s" domain. Grant access to that domain on the service or alias.', $this->currentId, $id, $domain));
                    $access = 'forbidden';
                }
            }
        }
        if (null === $access) {
            if (array_intersect($this->source['domains'], $target['domains'])) {
                $access = 'member';
            } elseif (array_intersect($this->source['domains'], $target['allowed'])) {
                $access = 'grant';
            } elseif ($target['exposed']) {
                $access = 'exposed';
            } elseif ($target['domains']) {
                $this->sourceDefinition->addError(\sprintf('The service "%s" cannot reference "%s": this service is internal to the "%s" domain. Use an exposed alias or explicitly allow access to this domain.', $this->currentId, $id, implode('", "', $target['domains'])));
                $access = 'forbidden';
            }
        }
        if ($target['domains'] || $target['allowed'] || $this->source['domains'] || $this->source['allowed'] || null !== $allowed) {
            $reference = ['service' => $id, 'domains' => $target['domains'], 'access' => $access ?? 'unrestricted', 'allow_domain' => $allowed];
            if (!\in_array($reference, $this->references[$this->currentId] ?? [], true)) {
                $this->references[$this->currentId][] = $reference;
            }
        }

        return $value;
    }

    private function isLocator(Definition $definition): bool
    {
        return !$definition->hasTag('container.domain') && !$definition->hasTag('container.exposed_to') && ($definition->hasTag('container.service_locator') || $definition->hasTag('container.service_locator_context'));
    }
}
