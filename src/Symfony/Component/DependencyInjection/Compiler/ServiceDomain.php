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
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;

/**
 * @internal
 */
final class ServiceDomain
{
    public function __construct(private ContainerBuilder $container)
    {
    }

    public function get(string $id, array $path = []): array
    {
        if ($this->container->hasAlias($id)) {
            if (isset($path[$id])) {
                throw new ServiceCircularReferenceException($id, [...array_keys($path), $id]);
            }
            $path[$id] = true;
            $alias = $this->container->getAlias($id);
            $info = $this->get((string) $alias, $path);
            if (null !== $alias->getDomains()) {
                $info['domains'] = $alias->getDomains();
                $info['exposed'] = $alias->isExposed() ?? false;
                $info['allowed'] = $alias->getAllowedDomains();
            } else {
                $info['exposed'] = $info['exposed'] || ($alias->isExposed() ?? $this->isAutowiringAlias($id));
                $info['allowed'] = array_values(array_unique([...$info['allowed'], ...$alias->getAllowedDomains()]));
            }

            foreach ([...$info['domains'], ...$info['allowed']] as $domain) {
                $this->validateName($domain, $id);
            }

            return $info;
        }

        return $this->container->hasDefinition($id) ? $this->getDefinition($this->container->getDefinition($id), $id) : ['domains' => [], 'restricted' => [], 'exposed' => false, 'allowed' => []];
    }

    public function getDefinition(Definition $definition, string $id): array
    {
        $info = ['domains' => [], 'restricted' => [], 'exposed' => false, 'allowed' => []];

        foreach ($definition->getTag('container.domain') as $tag) {
            $this->validateName($domain = $tag['domain'] ?? null, $id);
            foreach (['exposed', 'restricted'] as $key) {
                if (\array_key_exists($key, $tag) && !\is_bool($tag[$key])) {
                    throw new InvalidArgumentException(\sprintf('The "%s" attribute of the "container.domain" tag on service "%s" must be a boolean.', $key, $id));
                }
            }
            $info['domains'][] = $domain;
            $info['exposed'] = $info['exposed'] || ($tag['exposed'] ?? false);
            if ($tag['restricted'] ?? false) {
                $info['restricted'][] = $domain;
            }
        }
        foreach ($definition->getTag('container.exposed_to') as $tag) {
            $this->validateName($domain = $tag['domain'] ?? null, $id);
            $info['allowed'][] = $domain;
        }
        foreach (['domains', 'restricted', 'allowed'] as $key) {
            $info[$key] = array_values(array_unique($info[$key]));
            sort($info[$key]);
        }

        return $info;
    }

    private function isAutowiringAlias(string $id): bool
    {
        $type = explode(' $', ltrim($id, '.'), 2)[0];
        foreach (preg_split('/[&|()]/', $type, -1, \PREG_SPLIT_NO_EMPTY) as $class) {
            if (!$this->container->getReflectionClass($class, false)) {
                return false;
            }
        }

        return '' !== $type;
    }

    private function validateName(mixed $domain, string $id): void
    {
        if (!\is_string($domain) || '' === $domain) {
            throw new InvalidArgumentException(\sprintf('A service domain must be a non-empty string on service "%s".', $id));
        }
    }
}
