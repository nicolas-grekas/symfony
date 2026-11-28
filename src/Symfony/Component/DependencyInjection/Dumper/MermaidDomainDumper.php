<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Dumper;

use Symfony\Component\DependencyInjection\Dumper\Domain\DomainGraph;
use Symfony\Component\DependencyInjection\Dumper\Domain\DomainGraphCollector;

/**
 * MermaidDomainDumper dumps the service domains of a container and the references between them as a Mermaid flowchart.
 *
 * The domains that share a prefix, the part of their name before the first dot, are drawn in a subgraph.
 * An arrow points from the domain that references services to the domain that provides them and
 * counts these references. A dependency in both directions is drawn thick, and a dependency with
 * references that the domains don't allow is drawn dashed.
 */
final class MermaidDomainDumper extends Dumper
{
    private const DOMAIN_STYLE = 'fill:#d1e7dd,stroke:#2f6f5a,color:#16201b';
    private const GROUP_STYLE = 'fill:#cfe2ff,stroke:#3d6bb3,color:#16201b';
    private const CYCLE_STYLE = 'stroke:#fd7e14,stroke-width:3px';
    private const VIOLATION_STYLE = 'stroke:#dc3545,color:#dc3545';

    /**
     * Dumps the service domains as a Mermaid flowchart.
     *
     * Available options:
     *
     *  * collapse: Whether to merge the domains that share a prefix into one node (false by default)
     */
    public function dump(array $options = []): string
    {
        $graph = (new DomainGraphCollector())->collect($this->container);

        if ($options['collapse'] ?? false) {
            $graph = $graph->collapse();
        }

        $lines = ['flowchart LR'];

        foreach ($this->groupByPrefix($graph->domains) as $prefix => $domains) {
            if ('' === $prefix) {
                foreach ($domains as $domain) {
                    $lines[] = \sprintf('    %s["%s"]', $this->id($domain), \in_array($domain, $graph->groups, true) ? $domain.'.*' : $domain);
                }

                continue;
            }

            $lines[] = \sprintf('    subgraph %s ["%s"]', $this->id('group.'.$prefix), $prefix);
            foreach ($domains as $domain) {
                $lines[] = \sprintf('        %s["%s"]', $this->id($domain), substr($domain, \strlen($prefix) + 1));
            }
            $lines[] = '    end';
        }

        $linkStyles = [];

        foreach ($graph->dependencies as $i => $dependency) {
            $label = (string) \count($dependency->references);

            if ($violations = $dependency->countViolations()) {
                $arrow = '-.->';
                $label .= \sprintf(', %d not allowed', $violations);
                $linkStyles[] = \sprintf('    linkStyle %d %s', $i, self::VIOLATION_STYLE);
            } elseif ($graph->dependsOn($dependency->provider, $dependency->consumer)) {
                $arrow = '==>';
                $linkStyles[] = \sprintf('    linkStyle %d %s', $i, self::CYCLE_STYLE);
            } else {
                $arrow = '-->';
            }

            $lines[] = \sprintf('    %s %s|"%s"| %s', $this->id($dependency->consumer), $arrow, $label, $this->id($dependency->provider));
        }

        $classes = [
            'domain' => [self::DOMAIN_STYLE, array_values(array_diff($graph->domains, $graph->groups))],
            'group' => [self::GROUP_STYLE, $graph->groups],
        ];

        foreach ($classes as $class => [$style, $members]) {
            if ($members) {
                $lines[] = \sprintf('    classDef %s %s', $class, $style);
                $lines[] = \sprintf('    class %s %s', implode(',', array_map($this->id(...), $members)), $class);
            }
        }

        return implode("\n", [...$lines, ...$linkStyles])."\n";
    }

    /**
     * @param list<string> $domains
     *
     * @return array<string, list<string>>
     */
    private function groupByPrefix(array $domains): array
    {
        $prefixes = [];

        foreach ($domains as $domain) {
            $prefix = DomainGraph::groupOf($domain);
            $prefixes[$prefix === $domain ? '' : $prefix][] = $domain;
        }

        ksort($prefixes);

        return $prefixes;
    }

    private function id(string $name): string
    {
        // A prefix keeps ids like "end" from being read as Mermaid keywords
        return 'd_'.preg_replace('/\W+/', '_', $name);
    }
}
