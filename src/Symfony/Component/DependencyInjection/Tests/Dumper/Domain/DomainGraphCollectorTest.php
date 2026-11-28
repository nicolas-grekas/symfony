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
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\Domain\DomainDependency;
use Symfony\Component\DependencyInjection\Dumper\Domain\DomainGraphCollector;
use Symfony\Component\DependencyInjection\Dumper\Domain\ServiceReference;
use Symfony\Component\DependencyInjection\Reference;

class DomainGraphCollectorTest extends TestCase
{
    public function testRecordsWhetherAReferenceToAnotherDomainIsAllowed()
    {
        $container = new ContainerBuilder();
        $this->register($container, 'checkout', 'billing', references: ['shipments', 'tracker']);
        $this->register($container, 'shipments', 'shipping', exposed: true);
        $this->register($container, 'tracker', 'shipping');

        $graph = (new DomainGraphCollector())->collect($container);

        $this->assertSame(['billing', 'shipping'], $graph->domains);
        $this->assertEquals([new DomainDependency('billing', 'shipping', [
            new ServiceReference('checkout', 'shipments', true),
            new ServiceReference('checkout', 'tracker', false),
        ])], $graph->dependencies);
    }

    public function testIgnoresTheReferencesInsideADomainAndToServicesOutsideAnyDomain()
    {
        $container = new ContainerBuilder();
        $this->register($container, 'checkout', 'billing', references: ['invoices', 'logger']);
        $this->register($container, 'invoices', 'billing');
        $container->register('logger', \stdClass::class);

        $this->assertSame([], (new DomainGraphCollector())->collect($container)->dependencies);
    }

    public function testDoesNotAllowARestrictedServiceToReferenceAnExposedServiceOfAnotherDomain()
    {
        $container = new ContainerBuilder();
        $this->register($container, 'migration', 'migrations', restricted: true, references: ['shipments']);
        $this->register($container, 'shipments', 'shipping', exposed: true);

        $dependencies = (new DomainGraphCollector())->collect($container)->dependencies;

        $this->assertFalse($dependencies[0]->references[0]->allowed);
    }

    public function testCollectsTheReferencesOfAHiddenLocatorAsReferencesOfItsConsumer()
    {
        $container = new ContainerBuilder();
        $container->register('.locator', \stdClass::class)
            ->setArguments([['shipments' => new ServiceClosureArgument(new Reference('shipments'))]]);
        $this->register($container, 'checkout', 'billing', references: ['.locator']);
        $this->register($container, 'shipments', 'shipping', exposed: true);

        $this->assertEquals(
            [new DomainDependency('billing', 'shipping', [new ServiceReference('checkout', 'shipments', true)])],
            (new DomainGraphCollector())->collect($container)->dependencies,
        );
    }

    public function testRecordsTheIdThatWasReferencedAndChecksTheServiceBehindIt()
    {
        $container = new ContainerBuilder();
        $this->register($container, 'checkout', 'billing', references: ['shipping_api']);
        $this->register($container, 'shipments', 'shipping', exposed: true);
        $container->setAlias('shipping_api', 'shipments');

        $this->assertEquals(
            [new DomainDependency('billing', 'shipping', [new ServiceReference('checkout', 'shipping_api', true)])],
            (new DomainGraphCollector())->collect($container)->dependencies,
        );
    }

    public function testChecksADecoratedServiceAsTheServiceItDecorates()
    {
        $container = new ContainerBuilder();
        $this->register($container, 'checkout', 'billing', references: ['shipments']);
        $this->register($container, 'shipments', 'shipping');
        $this->register($container, 'traceable_shipments', 'shipping', exposed: true)->setDecoratedService('shipments');
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();

        $references = (new DomainGraphCollector())->collect($container)->dependencies[0]->references;

        $this->assertFalse($references[0]->allowed);
    }

    /**
     * @param list<string> $references
     */
    private function register(ContainerBuilder $container, string $id, string $domain, bool $exposed = false, bool $restricted = false, array $references = []): Definition
    {
        return $container->register($id, \stdClass::class)
            ->setPublic(true)
            ->addTag('container.domain', ['domain' => $domain, 'exposed' => $exposed, 'restricted' => $restricted])
            ->setArguments(array_map(static fn (string $id) => new Reference($id), $references));
    }
}
