<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Dumper\Domain;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Dumper\Domain\DomainDependency;
use Symfony\Component\DependencyInjection\Dumper\Domain\DomainGraph;
use Symfony\Component\DependencyInjection\Dumper\Domain\ServiceReference;

class DomainGraphTest extends TestCase
{
    public function testCollapsesTheDomainsThatShareAPrefixAndMergesTheirReferences()
    {
        $graph = new DomainGraph(
            ['billing', 'infrastructure.cache', 'infrastructure.doctrine', 'interface.mcp', 'interface.web'],
            [
                new DomainDependency('billing', 'infrastructure.cache', [new ServiceReference('prices', 'pool', true)]),
                new DomainDependency('billing', 'infrastructure.doctrine', [new ServiceReference('invoices', 'factory', false)]),
                new DomainDependency('interface.mcp', 'interface.web', [new ServiceReference('overview', 'dashboard', true)]),
                new DomainDependency('interface.web', 'billing', [new ServiceReference('dashboard', 'invoice_tile', true)]),
            ],
        );

        $this->assertEquals(new DomainGraph(
            ['billing', 'infrastructure', 'interface'],
            [
                new DomainDependency('billing', 'infrastructure', [
                    new ServiceReference('prices', 'pool', true),
                    new ServiceReference('invoices', 'factory', false),
                ]),
                new DomainDependency('interface', 'billing', [new ServiceReference('dashboard', 'invoice_tile', true)]),
            ],
            ['infrastructure', 'interface'],
        ), $graph->collapse());
    }
}
