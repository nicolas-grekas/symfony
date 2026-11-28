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

use Symfony\Component\DependencyInjection\Dumper\Domain\DomainGraphCollector;

/**
 * MarkdownDomainDumper lists the references between the service domains of a container as Markdown.
 */
final class MarkdownDomainDumper extends Dumper
{
    /**
     * Dumps the references between the service domains as Markdown.
     *
     * Available options:
     *
     *  * collapse: Whether to merge the domains that share a prefix into one domain (false by default)
     */
    public function dump(array $options = []): string
    {
        $graph = (new DomainGraphCollector())->collect($this->container);

        if ($options['collapse'] ?? false) {
            $graph = $graph->collapse();
        }

        $sections = [];

        foreach ($graph->dependencies as $dependency) {
            $section = \sprintf("### %s → %s (%d)\n", $dependency->consumer, $dependency->provider, \count($dependency->references));

            foreach ($dependency->references as $reference) {
                $section .= \sprintf("\n- `%s` → `%s`%s", $reference->consumer, $reference->service, $reference->allowed ? '' : ' (not allowed)');
            }

            $sections[] = $section;
        }

        return $sections ? implode("\n\n", $sections)."\n" : '';
    }
}
