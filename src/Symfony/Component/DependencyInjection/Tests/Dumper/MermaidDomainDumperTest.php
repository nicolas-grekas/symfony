<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Dumper;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\MermaidDomainDumper;
use Symfony\Component\DependencyInjection\Reference;

class MermaidDomainDumperTest extends TestCase
{
    public function testDump()
    {
        $this->assertSame(<<<'MERMAID'
            flowchart LR
                d_billing["billing"]
                d_shipping["shipping"]
                subgraph d_group_infrastructure ["infrastructure"]
                    d_infrastructure_doctrine["doctrine"]
                end
                d_billing -->|"1"| d_infrastructure_doctrine
                d_billing -.->|"2, 1 not allowed"| d_shipping
                d_shipping ==>|"1"| d_billing
                classDef domain fill:#d1e7dd,stroke:#2f6f5a,color:#16201b
                class d_billing,d_infrastructure_doctrine,d_shipping domain
                linkStyle 1 stroke:#dc3545,color:#dc3545
                linkStyle 2 stroke:#fd7e14,stroke-width:3px

            MERMAID, (new MermaidDomainDumper($this->createContainer()))->dump());
    }

    public function testDumpCollapsed()
    {
        $dump = (new MermaidDomainDumper($this->createContainer()))->dump(['collapse' => true]);

        $this->assertStringContainsString('d_infrastructure["infrastructure.*"]', $dump);
        $this->assertStringContainsString('d_billing -->|"1"| d_infrastructure', $dump);
        $this->assertStringContainsString('class d_billing,d_shipping domain', $dump);
        $this->assertStringContainsString('class d_infrastructure group', $dump);
    }

    public function testDumpPrefixesNodeIds()
    {
        $container = new ContainerBuilder();
        $container->register('foo', \stdClass::class)->addTag('container.domain', ['domain' => 'end']);

        $this->assertStringContainsString('d_end["end"]', (new MermaidDomainDumper($container))->dump());
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('billing.checkout', \stdClass::class)
            ->addTag('container.domain', ['domain' => 'billing', 'exposed' => true])
            ->setArguments([new Reference('shipping.shipments'), new Reference('shipping.tracker'), new Reference('infrastructure.doctrine.factory')]);
        $container->register('shipping.shipments', \stdClass::class)
            ->addTag('container.domain', ['domain' => 'shipping', 'exposed' => true])
            ->setArguments([new Reference('billing.checkout')]);
        $container->register('shipping.tracker', \stdClass::class)
            ->addTag('container.domain', ['domain' => 'shipping']);
        $container->register('infrastructure.doctrine.factory', \stdClass::class)
            ->addTag('container.domain', ['domain' => 'infrastructure.doctrine', 'exposed' => true]);

        return $container;
    }
}
