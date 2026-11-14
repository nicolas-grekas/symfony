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
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\LazyProxyArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Domain;
use Symfony\Component\DependencyInjection\Attribute\ExposeTo;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\TypedReference;
use Symfony\Component\ExpressionLanguage\Expression;

class CheckServiceDomainsPassTest extends TestCase
{
    #[DataProvider('provideInternalReferences')]
    public function testInternalServicesCannotBeReferencedFromOutsideTheirDomain(string $kind)
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing'])->addTag('handler');
        $container->setAlias('internal_alias', 'internal');
        $argument = match ($kind) {
            'direct' => new Reference('internal'),
            'alias' => new Reference('internal_alias'),
            'locator' => new ServiceLocatorArgument(['internal' => new Reference('internal')]),
            'iterator' => new TaggedIteratorArgument('handler'),
            'expression' => new Expression('service("internal")'),
            'inline' => new Definition(DomainConsumer::class, [new Reference('internal')]),
            'lazy' => new LazyProxyArgument(new TypedReference('internal', \stdClass::class)),
        };
        $container->register('consumer', DomainConsumer::class)->setArguments([$argument])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('internal to the "billing" domain');

        $container->compile();
    }

    public static function provideInternalReferences(): iterable
    {
        yield ['direct'];
        yield ['alias'];
        yield ['locator'];
        yield ['iterator'];
        yield ['expression'];
        yield ['inline'];
        yield ['lazy'];
    }

    public function testServicesInTheSameDomainCanReferenceEachOther()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([new Reference('internal')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public function testAnExposedDefinitionCanBeReferencedFromOutsideItsDomain()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing', 'exposed' => true]);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public function testAnUnusedViolationDoesNotPreventCompilation()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('unused', DomainConsumer::class)->setArguments([new Reference('internal')]);

        $container->compile();

        $this->assertFalse($container->has('unused'));
    }

    public function testDeclaredAutowiringAliasesExposeTheService()
    {
        $container = new ContainerBuilder();
        $container->register('internal', DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->setAlias(DomainApi::class, 'internal');
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference(DomainApi::class)])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(DomainImplementation::class, $container->get('consumer')->dependency);
    }

    public function testAReferenceCanAcknowledgeAccessToADomain()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, 'billing')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public function testRestrictedConsumersCannotUseUntaggedServices()
    {
        $container = new ContainerBuilder();
        $container->register('mailer', \stdClass::class);
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new Reference('mailer')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available to the restricted "migrations" domain');

        $container->compile();
    }

    public function testGrantsOnlyRestrictDirectDependencies()
    {
        $container = new ContainerBuilder();
        $container->register('configuration', \stdClass::class);
        $container->register('connection', DomainConsumer::class)->addTag('container.exposed_to', ['domain' => 'migrations'])->setArguments([new Reference('configuration')]);
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new Reference('connection')])->setPublic(true);
        $container->register('application', DomainConsumer::class)->setArguments([new Reference('connection')])->setPublic(true);

        $container->compile();

        $this->assertSame($container->get('application')->dependency, $container->get('migration')->dependency);
        $this->assertInstanceOf(\stdClass::class, $container->get('migration')->dependency->dependency);
    }

    public function testExposingAnAliasDoesNotGrantItToRestrictedDomains()
    {
        $container = new ContainerBuilder();
        $container->register('internal', DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->setAlias(DomainApi::class, 'internal');
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new Reference(DomainApi::class)])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available to the restricted "migrations" domain');

        $container->compile();
    }

    public function testAttributesConfigureMembershipAndGrants()
    {
        $container = new ContainerBuilder();
        $container->register(DomainMigration::class)->setAutoconfigured(true)->setAutowired(true)->setPublic(true);
        $container->register(DomainConnection::class)->setAutoconfigured(true);

        $container->compile();

        $this->assertInstanceOf(DomainConnection::class, $container->get(DomainMigration::class)->connection);
    }

    public function testAutowireAttributePreservesAnAllowance()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register(DomainAllowedConsumer::class)->setAutowired(true)->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get(DomainAllowedConsumer::class)->dependency);
    }

    public function testInferredAutowiringAliasesDoNotExposeServices()
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Fixtures'));
        $loader->load('domain_services.yml');
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference(\Symfony\Component\DependencyInjection\Tests\Fixtures\Domain\Contract::class)])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('internal to the "billing" domain');

        $container->compile();
    }

    public function testDecorationPreservesInternalVisibility()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('decorator', DomainConsumer::class)->setDecoratedService('internal')->setArguments([new Reference('.inner')]);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('internal to the "billing" domain');

        $container->compile();
    }

    public function testDecorationPreservesAliasExposure()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->setAlias('api', 'internal')->setExposed(true);
        $container->register('decorator', DomainConsumer::class)->setDecoratedService('api')->setArguments([new Reference('.inner')]);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('api')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency->dependency);
    }

    public function testDecoratingAnApiDoesNotGrantAccessToOtherInternals()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('other_internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->setAlias('api', 'internal')->setExposed(true);
        $container->register('decorator', DomainConsumer::class)->setDecoratedService('api')->setArguments([new Reference('other_internal')]);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('api')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('service "decorator" cannot reference "other_internal"');

        $container->compile();
    }

    public function testAnAllowanceMustMatchTheTargetDomain()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, 'billing')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong to that domain');

        $container->compile();
    }

    public function testDecorationKeepsTheOriginalServicesDomain()
    {
        $container = new ContainerBuilder();
        $container->register('dependency', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('internal', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing', 'exposed' => true])->setArguments([new Reference('dependency')]);
        $container->register('decorator', DomainConsumer::class)->setDecoratedService('internal')->setArguments([new Reference('.inner')]);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal')])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency->dependency->dependency);
    }

    public function testADecoratorDoesNotJoinTheDecoratedServicesDomain()
    {
        $container = new ContainerBuilder();
        $container->register('dependency', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing', 'exposed' => true]);
        $container->register('decorator', DomainConsumer::class)->setDecoratedService('internal')->setArguments([new Reference('dependency')]);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('service "decorator" cannot reference "dependency"');

        $container->compile();
    }

    public function testRestrictedConsumersCanUseLocatorsOfGrantedServices()
    {
        $container = new ContainerBuilder();
        $container->register('connection', \stdClass::class)->addTag('container.exposed_to', ['domain' => 'migrations']);
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new ServiceLocatorArgument(['connection' => new Reference('connection')])])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('migration')->dependency->get('connection'));
    }

    public function testASharedLocatorIsCheckedForEachConsumer()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        foreach (['billing', 'other'] as $domain) {
            $container->register($domain, DomainConsumer::class)->addTag('container.domain', ['domain' => $domain])->setArguments([new ServiceLocatorArgument(['internal' => new Reference('internal')])])->setPublic(true);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('service "other" cannot reference "internal"');

        $container->compile();
    }

    public function testAnExposedDecoratorDoesNotExposeTheDecoratedId()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('decorator', DomainConsumer::class)->addTag('container.domain', ['domain' => 'other', 'exposed' => true])->setDecoratedService('internal')->setArguments([new Reference('.inner')]);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('internal to the "billing" domain');

        $container->compile();
    }

    public function testDecorationDoesNotExposeTheGeneratedInnerAlias()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->setAlias('api', 'internal')->setExposed(true);
        $container->register('decorator', DomainConsumer::class)->setDecoratedService('api')->setArguments([new Reference('.inner')]);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('decorator.inner')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('internal to the "billing" domain');

        $container->compile();
    }

    public function testASameDomainLazyReferenceIsAllowed()
    {
        $container = new ContainerBuilder();
        $container->register('internal', DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([new LazyProxyArgument(new TypedReference('internal', DomainApi::class))])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(DomainImplementation::class, $container->get('consumer')->dependency);
    }

    public function testAnAuditKeepsAnExceptionWhenAnotherReferenceUsesMembership()
    {
        $container = new ContainerBuilder();
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->register('consumer', \ArrayObject::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([[new Reference('internal', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, 'billing'), new Reference('internal')]])->setPublic(true);

        $container->compile();

        $this->assertSame(['reference', 'member'], array_column(json_decode($container->getParameter('.container.domain_references'), true, 512, \JSON_THROW_ON_ERROR)['consumer'], 'access'));
    }

    public function testAPublicServiceCanStillBeInternal()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing'])->setPublic(true);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('internal to the "billing" domain');

        $container->compile();
    }

    public function testAnAutowiringAliasCanOptOutOfExposure()
    {
        $container = new ContainerBuilder();
        $container->register('internal', DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->setAlias(DomainApi::class, 'internal')->setExposed(false);
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference(DomainApi::class)])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('internal to the "billing" domain');

        $container->compile();
    }

    public function testAGrantOnAnAliasDoesNotGrantTheDefinition()
    {
        $container = new ContainerBuilder();
        $container->register('internal', DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->setAlias(DomainApi::class, 'internal')->setAllowedDomains(['migrations']);
        $container->register('migration', DomainConsumer::class)->addTag('container.domain', ['domain' => 'migrations', 'restricted' => true])->setArguments([new Reference('internal')])->setPublic(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available to the restricted "migrations" domain');

        $container->compile();
    }

    #[DataProvider('provideMemberships')]
    public function testMultipleMemberships(array $source, array $target, array $allowed, bool $valid)
    {
        $container = new ContainerBuilder();
        $dependency = $container->register('dependency', \stdClass::class);
        foreach ($target as $domain) {
            $dependency->addTag('container.domain', ['domain' => $domain]);
        }
        foreach ($allowed as $domain) {
            $dependency->addTag('container.exposed_to', ['domain' => $domain]);
        }
        $consumer = $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('dependency')])->setPublic(true);
        foreach ($source as $domain => $restricted) {
            $consumer->addTag('container.domain', ['domain' => $domain, 'restricted' => $restricted]);
        }
        if (!$valid) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not available to the restricted "audit" domain');
        }

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('consumer')->dependency);
    }

    public static function provideMemberships(): iterable
    {
        yield [['billing' => false, 'shipping' => false], ['shipping'], [], true];
        yield [['migrations' => true], ['billing', 'migrations'], [], true];
        yield [['migrations' => true, 'audit' => true], [], ['migrations', 'audit'], true];
        yield [['migrations' => true, 'audit' => true], [], ['migrations'], false];
    }

    public function testOptionalMissingDependenciesKeepTheirExistingBehavior()
    {
        $container = new ContainerBuilder();
        $container->setAlias('missing_alias', 'missing_service');
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([new Reference('missing_alias', ContainerInterface::NULL_ON_INVALID_REFERENCE)])->setPublic(true);

        $container->compile();

        $this->assertNull($container->get('consumer')->dependency);
    }

    public function testTheDumpedContainerUsesTheSameServices()
    {
        $container = new ContainerBuilder();
        $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        $container->setAlias('api', 'internal')->setExposed(true)->setPublic(true);
        $container->register('consumer', \ArrayObject::class)->setArguments([[new Reference('api')]])->setPublic(true);
        $container->compile();
        $dump = (new PhpDumper($container))->dump(['class' => 'ServiceDomainsTestContainer']);
        eval('?>'.$dump);
        $compiled = new \ServiceDomainsTestContainer();

        $this->assertSame($compiled->get('api'), $compiled->get('consumer')[0]);
        $this->assertStringNotContainsString('.container.domains', $dump);
        $this->assertStringNotContainsString('.container.domain_references', $dump);
    }

    public function testTheAuditIncludesDependenciesOutsideDomains()
    {
        $container = new ContainerBuilder();
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
        $container->register('configuration', \stdClass::class);
        $container->register('consumer', DomainConsumer::class)->addTag('container.domain', ['domain' => 'billing'])->setArguments([new Reference('configuration')])->setPublic(true);

        $container->compile();

        $references = json_decode($container->getParameter('.container.domain_references'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame([['service' => 'configuration', 'domains' => [], 'access' => 'unrestricted', 'allow_domain' => null]], $references['consumer'] ?? []);
    }

    #[DataProvider('provideReferenceReplacements')]
    public function testReferenceReplacementsPreserveAllowances(bool $deprecated)
    {
        $container = new ContainerBuilder();
        $definition = $container->register('internal', \stdClass::class)->addTag('container.domain', ['domain' => 'billing']);
        if ($deprecated) {
            $definition->setPublic(true)->addTag('container.private', ['package' => 'test', 'version' => '1.0']);
        } else {
            $container->setAlias('api', 'internal')->setExposed(true)->setPublic(true);
        }
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('internal', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, 'billing')])->setPublic(true);

        $container->compile();

        $this->assertSame('billing', $container->getDefinition('consumer')->getArgument(0)->getAllowedDomain());
    }

    public static function provideReferenceReplacements(): iterable
    {
        yield [false];
        yield [true];
    }

    #[DataProvider('provideCombinedTypes')]
    public function testCombinedTypesPreserveAliasExposure(string $glue, ?string $name)
    {
        $container = new ContainerBuilder();
        $container->register('internal', DomainImplementation::class)->addTag('container.domain', ['domain' => 'billing']);
        $suffix = null === $name ? '' : ' $'.$name;
        $container->setAlias(DomainApi::class.$suffix, 'internal');
        $container->setAlias(\Stringable::class.$suffix, 'internal');
        $type = DomainApi::class.$glue.\Stringable::class;
        $container->register('consumer', DomainConsumer::class)->setArguments([new TypedReference($type, $type, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $name)])->setPublic(true);

        $container->compile();

        $this->assertInstanceOf(DomainImplementation::class, $container->get('consumer')->dependency);
    }

    public static function provideCombinedTypes(): iterable
    {
        yield ['&', null];
        yield ['|', null];
        yield ['&', 'selected'];
        yield ['|', 'selected'];
    }

    public function testAnAllowanceDoesNotHideAnAliasCycle()
    {
        $container = new ContainerBuilder();
        $container->setAlias('first', 'second');
        $container->setAlias('second', 'first');
        $container->register('consumer', DomainConsumer::class)->setArguments([new Reference('first', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, 'billing')])->setPublic(true);

        $this->expectException(ServiceCircularReferenceException::class);

        $container->compile();
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

class DomainImplementation implements DomainApi, \Stringable
{
    public function __toString(): string
    {
        return 'billing';
    }
}

#[Domain('migrations', restricted: true)]
class DomainMigration
{
    public function __construct(public DomainConnection $connection)
    {
    }
}

#[ExposeTo('migrations')]
class DomainConnection
{
}

class DomainAllowedConsumer
{
    public function __construct(#[Autowire(service: 'internal', allowDomain: 'billing')] public mixed $dependency)
    {
    }
}
