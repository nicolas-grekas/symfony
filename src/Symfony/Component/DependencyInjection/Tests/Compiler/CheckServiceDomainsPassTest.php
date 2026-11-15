<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Compiler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\LazyProxyArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Attribute\Domain;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Tests\Fixtures\Domain\Contract;
use Symfony\Component\DependencyInjection\TypedReference;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

class CheckServiceDomainsPassTest extends TestCase
{
    public function testServicesOfTheSameDomainCanReferenceEachOther()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([new Reference('internal')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    #[DataProvider('provideReferences')]
    public function testAnInternalServiceCannotBeReferencedFromAnotherDomain(string $kind)
    {
        $container = $this->createContainerWithReference($kind, false);

        $this->expectException(RuntimeException::class);
        $id = 'alias' === $kind ? 'alias' : 'target';
        $this->expectExceptionMessage('The service "consumer" cannot reference "'.$id.'": "'.$id.'" is internal to the "billing" domain. Expose it or add "consumer" to this domain.');

        $container->compile();
    }

    #[DataProvider('provideReferences')]
    public function testAnExposedServiceCanBeReferencedFromAnotherDomain(string $kind)
    {
        $container = $this->createContainerWithReference($kind, true);

        $container->compile();

        $this->assertInstanceOf(DomainConsumer::class, $container->get('consumer'));
    }

    public static function provideReferences(): iterable
    {
        yield ['direct'];
        yield ['alias'];
        yield ['locator'];
        yield ['iterator'];
        yield ['expression'];
        yield ['inline'];
        yield ['lazy'];
        yield ['hidden'];
    }

    public function testAServiceOutsideAnyDomainIsNotChecked()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public function testAServiceOutsideAnyDomainCanBeReferenced()
    {
        $container = new ContainerBuilder();
        $container->register('logger', \stdClass::class);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([new Reference('logger')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public function testAServiceCanBelongToSeveralDomains()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->addTag('container.domain', ['domain' => 'billing'])->setArguments([new Reference('internal')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public function testAnInternalServiceOfSeveralDomainsNamesThemAll()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing'])->addTag('container.domain', ['domain' => 'audit']);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference('internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"internal" is internal to the "billing", "audit" domains. Expose it or add "consumer" to one of these domains.');

        $container->compile();
    }

    public function testADomainDeclaredTwiceCountsOnce()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing'])->addTag('container.domain', ['domain' => 'billing', 'restricted' => true]);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping', 'restricted' => true])->addTag('container.domain', ['domain' => 'shipping', 'restricted' => true, 'exposed' => true])->setArguments([new Reference('internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "consumer" cannot reference "internal": "consumer" is restricted to the "shipping" domain. Add "internal" to this domain to allow it.');

        $container->compile();
    }

    public function testAServiceIsExposedWhenOneOfItsMembershipsExposesIt()
    {
        $container = new ContainerBuilder();
        $container->register('api', \stdClass::class)->addTag('container.domain', ['domain' => 'audit', 'exposed' => true])->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference('api')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public function testAViolationInAnUnusedServiceDoesNotPreventCompilation()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('unused', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference('internal')]);

        $container->compile();

        $this->assertFalse($container->has('unused'));
    }

    public function testAPublicServiceCanStillBeInternal()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing'])->setPublic(true);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference('internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"internal" is internal to the "billing" domain');

        $container->compile();
    }

    public function testACircularAliasIsNotFollowed()
    {
        $container = new ContainerBuilder();
        $container->setAlias('first', 'second');
        $container->setAlias('second', 'first');
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([new Reference('first')])->setPublic(true);

        $this->expectException(ServiceCircularReferenceException::class);

        $container->compile();
    }

    public function testAnOptionalReferenceToAMissingServiceIsIgnored()
    {
        $container = new ContainerBuilder();
        $container->setAlias('missing_alias', 'missing_service');
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing', 'restricted' => true])->setArguments([new Reference('missing_alias', ContainerBuilder::NULL_ON_INVALID_REFERENCE)])->setPublic(true);

        $container->compile();

        $this->assertNull($container->get('consumer')->dependency);
    }

    public function testARestrictedServiceCannotReferenceAServiceOutsideItsDomain()
    {
        $container = new ContainerBuilder();
        $container->register('mailer', \stdClass::class);
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new Reference('mailer')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "migration" cannot reference "mailer": "migration" is restricted to the "migrations" domain. Add "mailer" to this domain to allow it.');

        $container->compile();
    }

    public function testARestrictedServiceCannotReferenceAnExposedServiceOfAnotherDomain()
    {
        $container = new ContainerBuilder();
        $container->register('api', \stdClass::class)->addTag('container.domain', ['domain' => 'billing', 'exposed' => true]);
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new Reference('api')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"migration" is restricted to the "migrations" domain');

        $container->compile();
    }

    public function testAServiceAllowedInARestrictedDomainKeepsItsDependenciesAndItsConsumers()
    {
        $container = new ContainerBuilder();
        $container->register('configuration', \stdClass::class);
        $container->register('connection', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'exposed' => true])->setArguments([new Reference('configuration')]);
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new Reference('connection')])->setPublic(true);
        $container->register('application', DomainConsumer::class)->addTag('container.domain', ['domain' => 'app'])->setArguments([new Reference('connection')])->setPublic(true);

        $container->compile();

        $this->assertSame($container->get('application')->dependency, $container->get('migration')->dependency);
        $this->assertInstanceOf(\stdClass::class, $container->get('migration')->dependency->dependency);
    }

    #[DataProvider('provideRestrictedMemberships')]
    public function testEveryRestrictedDomainMustContainTheDependency(array $consumer, array $dependency, ?string $missing)
    {
        $container = new ContainerBuilder();
        $definition = $container->register('dependency', \stdClass::class);
        foreach ($dependency as $domain) {
            $definition->addTag('container.domain', ['domain' => $domain]);
        }
        $definition = $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('dependency')])->setPublic(true);
        foreach ($consumer as $domain => $restricted) {
            $definition->addTag('container.domain', ['domain' => $domain, 'restricted' => $restricted]);
        }

        if (null !== $missing) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('"consumer" is restricted to the "'.$missing.'" domain');
        }

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public static function provideRestrictedMemberships(): iterable
    {
        yield [['migrations' => true], ['billing', 'migrations'], null];
        yield [['migrations' => true, 'audit' => true], ['migrations', 'audit'], null];
        yield [['migrations' => true, 'audit' => true], ['migrations'], 'audit'];
        yield [['migrations' => true, 'billing' => false], ['billing'], 'migrations'];
    }

    #[DataProvider('provideGeneratedServices')]
    public function testARestrictedServiceCanUseGeneratedServicesThatHoldMembersOnly(string $kind, bool $member)
    {
        $container = new ContainerBuilder();
        $definition = $container->register('connection', DomainImplementation::class)->addTag('handler');
        if ($member) {
            $definition->addTag('container.domain', ['domain' => 'migrations', 'exposed' => true]);
        }
        $container->register('.hidden', DomainConsumer::class)->setArguments([new Reference('connection')]);
        $argument = match ($kind) {
            'locator' => new ServiceLocatorArgument(['connection' => new Reference('connection')]),
            'iterator' => new TaggedIteratorArgument('handler'),
            'lazy' => new LazyProxyArgument(new TypedReference('connection', DomainApi::class)),
            'hidden' => new Reference('.hidden'),
        };
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([$argument])->setPublic(true);

        if (!$member) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The service "migration" cannot reference "connection": "migration" is restricted to the "migrations" domain.');
        }

        $container->compile();

        $this->assertInstanceOf(DomainConsumer::class, $container->get('migration'));
    }

    public static function provideGeneratedServices(): iterable
    {
        foreach (['locator', 'iterator', 'lazy', 'hidden'] as $kind) {
            yield [$kind, true];
            yield [$kind, false];
        }
    }

    public function testAHiddenServiceOfADomainIsCheckedLikeAnyOtherService()
    {
        $container = new ContainerBuilder();
        $container->register('.internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference('.internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "consumer" cannot reference ".internal": ".internal" is internal to the "billing" domain.');

        $container->compile();
    }

    public function testALocatorSharedByTwoServicesIsCheckedForEachOfThem()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        foreach (['billing', 'shipping'] as $domain) {
            $container->register($domain, DomainConsumer::class)->addTag('container.domain', ['domain' => $domain])->setArguments([new ServiceLocatorArgument(['internal' => new Reference('internal')])])->setPublic(true);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "shipping" cannot reference "internal"');

        $container->compile();
    }

    #[DataProvider('provideLocatorContexts')]
    public function testALocatorIsCheckedAsTheServiceItWasCreatedFor(string $context, string $domain, bool $valid)
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('controller', \stdClass::class)->addTag('container.domain', ['domain' => $domain, 'exposed' => true])->setPublic(true);
        $container->register('resolver', DomainConsumer::class)->setArguments([ServiceLocatorTagPass::register($container, ['argument' => new Reference('internal')], $context)])->setPublic(true);

        if (!$valid) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The service "controller" cannot reference "internal": "internal" is internal to the "billing" domain.');
        }

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('resolver')->dependency->get('argument'));
    }

    public static function provideLocatorContexts(): iterable
    {
        yield ['controller', 'billing', true];
        yield ['controller', 'shipping', false];
        yield ['controller::action()', 'billing', true];
        yield ['controller::action()', 'shipping', false];
    }

    public function testAServiceSubscriberIsCheckedThroughItsLocator()
    {
        $container = new ContainerBuilder();
        $container->register(DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register(DomainSubscriber::class)->addTag('container.domain', ['domain' => 'shipping'])->addTag('container.service_subscriber')->setAutowired(true)->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "'.DomainSubscriber::class.'" cannot reference "'.DomainImplementation::class.'": "'.DomainImplementation::class.'" is internal to the "billing" domain.');

        $container->compile();
    }

    public function testARestrictedServiceSubscriberCanUseALocatorOfMembers()
    {
        $container = new ContainerBuilder();
        $container->register(DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register(DomainSubscriber::class)->addTag('container.domain', ['domain' => 'billing', 'restricted' => true])->addTag('container.service_subscriber')->setAutowired(true)->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(DomainImplementation::class, $container->get(DomainSubscriber::class)->locator->get(DomainImplementation::class));
    }

    public function testTheDomainAttributeDeclaresMemberships()
    {
        $container = new ContainerBuilder();
        $container->register(DomainMigration::class)->setAutoconfigured(true)->setAutowired(true)->setPublic(true);
        $container->register(DomainConnection::class)->setAutoconfigured(true);

        $container->compile();

        $this->assertSame([['domain' => 'migrations', 'exposed' => false, 'restricted' => true]], $container->getDefinition(DomainMigration::class)->getTag('container.domain'));
        $this->assertInstanceOf(DomainConnection::class, $container->get(DomainMigration::class)->connection);
    }

    public function testTheDomainAttributeIsEnforced()
    {
        $container = new ContainerBuilder();
        $container->register(DomainMigration::class)->setAutoconfigured(true)->setAutowired(true)->setPublic(true);
        $container->register(DomainConnection::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "'.DomainMigration::class.'" cannot reference "'.DomainConnection::class.'": "'.DomainMigration::class.'" is restricted to the "migrations" domain.');

        $container->compile();
    }

    public function testTheDomainAttributeOnAnInterfaceAppliesToItsImplementations()
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Fixtures'));
        $loader->load('domain_services.yml');
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference(Contract::class)])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "consumer" cannot reference "'.Contract::class.'": "'.Contract::class.'" is internal to the "billing" domain.');

        $container->compile();
    }

    #[DataProvider('provideDecoratedServices')]
    public function testDecorationDoesNotChangeWhoCanReferenceAService(bool $exposed)
    {
        $container = new ContainerBuilder();
        $container->register('decorated', \stdClass::class)->addTag('container.domain', ['domain' => 'billing', 'exposed' => $exposed]);
        $container->register('decorator', DomainConsumer::class)->setDecoratedService('decorated')->setArguments([new Reference('.inner')]);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference('decorated')])->setPublic(true);

        if (!$exposed) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The service "consumer" cannot reference "decorated": "decorated" is internal to the "billing" domain.');
        }

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency->dependency);
    }

    public static function provideDecoratedServices(): iterable
    {
        yield [true];
        yield [false];
    }

    public function testARestrictedServiceCanReferenceADecoratedMemberOfItsDomain()
    {
        $container = new ContainerBuilder();
        $container->register('stopwatch', \stdClass::class);
        $container->register('connection', \stdClass::class)->addTag('container.domain', ['domain' => 'migrations', 'exposed' => true]);
        $container->register('.debug.connection', DomainConsumer::class)->setDecoratedService('connection')->setArguments([new Reference('.inner'), new Reference('stopwatch')]);
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new Reference('connection')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('migration')->dependency->dependency);
    }

    public function testAnExposedDecoratorDoesNotExposeTheServiceItDecorates()
    {
        $container = new ContainerBuilder();
        $container->register('decorated', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('decorator', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing', 'exposed' => true])->setDecoratedService('decorated')->setArguments([new Reference('.inner')]);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference('decorated')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "consumer" cannot reference "decorated": "decorated" is internal to the "billing" domain.');

        $container->compile();
    }

    public function testADecoratorDoesNotJoinTheDomainOfTheServiceItDecorates()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('decorated', \stdClass::class)->addTag('container.domain', ['domain' => 'billing', 'exposed' => true]);
        $container->register('decorator', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setDecoratedService('decorated')->setArguments([new Reference('internal')]);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([new Reference('decorated')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The service "decorator" cannot reference "internal": "internal" is internal to the "billing" domain.');

        $container->compile();
    }

    #[DataProvider('provideDecoratorDomains')]
    public function testDecoratingAnInternalServiceRequiresBelongingToItsDomain(?string $domain, bool $valid)
    {
        $container = new ContainerBuilder();
        $container->register('decorated', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $decorator = $container->register('decorator', DomainConsumer::class)->setDecoratedService('decorated')->setArguments([new Reference('.inner')]);
        if (null !== $domain) {
            $decorator->addTag('container.domain', ['domain' => $domain]);
        }
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([new Reference('decorated')])->setPublic(true);

        if (!$valid) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The service "decorator" cannot reference "decorator.inner": "decorator.inner" is internal to the "billing" domain.');
        }

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency->dependency);
    }

    public static function provideDecoratorDomains(): iterable
    {
        yield ['billing', true];
        yield [null, true];
        yield ['shipping', false];
    }

    #[DataProvider('provideInvalidTags')]
    public function testInvalidTags(array $attributes, string $message)
    {
        $container = new ContainerBuilder();
        $container->register('foo', \stdClass::class)->addTag('container.domain', $attributes)->setPublic(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $container->compile();
    }

    public static function provideInvalidTags(): iterable
    {
        yield [[], 'The "container.domain" tag on service "foo" requires a non-empty "domain" attribute.'];
        yield [['domain' => ''], 'The "container.domain" tag on service "foo" requires a non-empty "domain" attribute.'];
        yield [['domain' => 'billing', 'exposed' => 'no'], 'The "exposed" attribute of the "container.domain" tag on service "foo" must be a boolean.'];
        yield [['domain' => 'billing', 'restricted' => 1], 'The "restricted" attribute of the "container.domain" tag on service "foo" must be a boolean.'];
    }

    private function createContainerWithReference(string $kind, bool $exposed): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('target', DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing', 'exposed' => $exposed])->addTag('handler')->setPublic(true);
        $container->setAlias('alias', 'target');
        $container->register('.hidden', DomainConsumer::class)->setArguments([new Reference('target')]);
        $argument = match ($kind) {
            'direct' => new Reference('target'),
            'alias' => new Reference('alias'),
            'locator' => new ServiceLocatorArgument(['target' => new Reference('target')]),
            'iterator' => new TaggedIteratorArgument('handler'),
            'expression' => new Expression('service("target")'),
            'inline' => new Definition(DomainConsumer::class, [new Reference('target')]),
            'lazy' => new LazyProxyArgument(new TypedReference('target', DomainApi::class)),
            'hidden' => new Reference('.hidden'),
        };
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'shipping'])->setArguments([$argument])->setPublic(true);

        return $container;
    }
}

class DomainConsumer
{
    public function __construct(public mixed $dependency)
    {
    }
}

interface DomainApi
{
}

class DomainImplementation implements DomainApi
{
}

#[Domain('migrations', restricted: true)]
class DomainMigration
{
    public function __construct(public DomainConnection $connection)
    {
    }
}

#[Domain('migrations', exposed: true)]
class DomainConnection
{
}

class DomainSubscriber implements ServiceSubscriberInterface
{
    public function __construct(public PsrContainerInterface $locator)
    {
    }

    public static function getSubscribedServices(): array
    {
        return [DomainImplementation::class];
    }
}
