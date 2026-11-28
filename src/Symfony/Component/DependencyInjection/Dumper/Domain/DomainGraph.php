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

/**
 * The domains of a container and the dependencies between them.
 *
 * @internal
 */
final class DomainGraph
{
    /**
     * @param list<string>           $domains
     * @param list<DomainDependency> $dependencies
     * @param list<string>           $groups       The domains that stand for a collapsed group of domains
     */
    public function __construct(
        public readonly array $domains,
        public readonly array $dependencies,
        public readonly array $groups = [],
    ) {
    }

    public function dependsOn(string $consumer, string $provider): bool
    {
        foreach ($this->dependencies as $dependency) {
            if ($dependency->consumer === $consumer && $dependency->provider === $provider) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges the domains that share a prefix, the part of their name before the first dot, into one group.
     */
    public function collapse(): self
    {
        $nodes = $groups = $references = [];

        foreach ($this->domains as $domain) {
            $nodes[$group = self::groupOf($domain)] = $group;

            if ($group !== $domain) {
                $groups[$group] = $group;
            }
        }

        foreach ($this->dependencies as $dependency) {
            $consumer = self::groupOf($dependency->consumer);
            $provider = self::groupOf($dependency->provider);

            if ($consumer !== $provider) {
                $references[$consumer][$provider] ??= [];
                array_push($references[$consumer][$provider], ...$dependency->references);
            }
        }

        $dependencies = [];
        ksort($references);

        foreach ($references as $consumer => $providers) {
            ksort($providers);

            foreach ($providers as $provider => $merged) {
                $dependencies[] = new DomainDependency($consumer, $provider, $merged);
            }
        }

        sort($nodes);
        sort($groups);

        return new self($nodes, $dependencies, $groups);
    }

    public static function groupOf(string $domain): string
    {
        return strstr($domain, '.', true) ?: $domain;
    }
}
