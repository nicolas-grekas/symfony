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
use Symfony\Component\DependencyInjection\Dumper\MarkdownDomainDumper;
use Symfony\Component\DependencyInjection\Reference;

class MarkdownDomainDumperTest extends TestCase
{
    public function testDump()
    {
        $this->assertSame(<<<'MARKDOWN'
            ### billing → infrastructure.doctrine (1)

            - `billing.checkout` → `infrastructure.doctrine.factory`

            ### billing → shipping (2)

            - `billing.checkout` → `shipping.shipments`
            - `billing.checkout` → `shipping.tracker` (not allowed)

            ### shipping → billing (1)

            - `shipping.shipments` → `billing.checkout`

            MARKDOWN, (new MarkdownDomainDumper($this->createContainer()))->dump());
    }

    public function testDumpCollapsed()
    {
        $dump = (new MarkdownDomainDumper($this->createContainer()))->dump(['collapse' => true]);

        $this->assertStringContainsString('### billing → infrastructure (1)', $dump);
    }

    public function testDumpWithoutDependencies()
    {
        $this->assertSame('', (new MarkdownDomainDumper(new ContainerBuilder()))->dump());
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
