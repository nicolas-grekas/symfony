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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Compiler\RegisterAsClosureAttributesPass;
use Symfony\Component\DependencyInjection\Compiler\RegisterClosureServicesPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\AbstractRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\BadAttributes;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\BarRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\CallableRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\CallableRuleInterface;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\ClosureTagInterface;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\ClosureTagRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\ConcreteRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\FooRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\InheritsMethod;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\Invokable;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\InvokableChild;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\InvokableFromInterface;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\InvokableInterface;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\InvokableOverride;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\KeyedTags;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\NonPublicMethod;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\NotInvokable;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\OtherCallableRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\OtherClosureTagRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\OverridesMethod;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\RuleInterface;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\Rules;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\SubRule;
use Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\SubRuleInterface;

#[CoversClass(RegisterAsClosureAttributesPass::class)]
#[CoversClass(RegisterClosureServicesPass::class)]
class RegisterClosureServicesPassTest extends TestCase
{
    public function testRegistersAClosureServicePerAttributedMethod()
    {
        $container = new ContainerBuilder();
        $container->register('app.rules', Rules::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.rules::isAdult');

        $this->assertSame('Closure', $definition->getClass());
        $this->assertSame(['Closure', 'fromCallable'], $definition->getFactory());
        $this->assertEquals([[new Reference('app.rules'), 'isAdult']], $definition->getArguments());
        $this->assertTrue($definition->isLazy());
        $this->assertFalse($definition->isPublic());
        $this->assertSame([[]], $definition->getTag('app.rule'));

        $this->assertFalse($container->has('app.rules::notExposed'));
    }

    public function testStaticMethodsDontReferenceTheDeclaringService()
    {
        $container = new ContainerBuilder();
        $container->register('app.rules', Rules::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.rule.is_even');

        $this->assertSame([[Rules::class, 'isEven']], $definition->getArguments());
        $this->assertFalse($definition->isLazy());
        $this->assertSame([['priority' => 10]], $definition->getTag('app.rule'));
    }

    public function testEachServiceOfTheSameClassGetsItsOwnClosureServices()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, RuleInterface::class);
        $container->register('app.foo_rule', FooRule::class)->setAutoconfigured(true);
        $container->register('app.spare_rule', FooRule::class)->setAutoconfigured(true);

        $this->process($container);

        $this->assertEquals([[new Reference('app.foo_rule'), 'evaluate']], $container->getDefinition('app.foo_rule::evaluate')->getArguments());
        $this->assertEquals([[new Reference('app.spare_rule'), 'evaluate']], $container->getDefinition('app.spare_rule::evaluate')->getArguments());
    }

    public function testTheDeclaringServiceIsInstantiatedOnTheFirstCall()
    {
        $container = new ContainerBuilder();
        $container->register('app.rules', Rules::class)->setAutoconfigured(true);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition('app.rules::isAdult')->setPublic(true);
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);

        $container->compile();

        try {
            $isAdult = $container->get('app.rules::isAdult');

            $this->assertInstanceOf(\Closure::class, $isAdult);
            $this->assertSame(0, Rules::$instantiations);

            $this->assertTrue($isAdult(20));
            $this->assertFalse($isAdult(10));
            $this->assertSame(1, Rules::$instantiations);
        } finally {
            Rules::$instantiations = 0;
        }
    }

    public function testNothingIsRegisteredWithoutAutoconfiguration()
    {
        $container = new ContainerBuilder();
        $container->register('app.rules', Rules::class);

        $this->process($container);

        $this->assertFalse($container->has('app.rules::isAdult'));
    }

    public function testNothingIsRegisteredWhenAttributesAreIgnored()
    {
        $container = new ContainerBuilder();
        $container->register('app.rules', Rules::class)
            ->setAutoconfigured(true)
            ->addTag('container.ignore_attributes');

        $this->process($container);

        $this->assertFalse($container->has('app.rules::isAdult'));
    }

    public function testAbstractServicesGetNoClosureService()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, RuleInterface::class);
        $container->register('app.abstract_rule', FooRule::class)
            ->setAbstract(true)
            ->setAutoconfigured(true);

        $this->process($container);

        $this->assertFalse($container->has('app.abstract_rule::evaluate'));
    }

    public function testAnInheritedMethodIsStillAttributed()
    {
        $container = new ContainerBuilder();
        $container->register('app.inherits', InheritsMethod::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.inherits::evaluate');

        $this->assertEquals([[new Reference('app.inherits'), 'evaluate']], $definition->getArguments());
        $this->assertSame([[]], $definition->getTag('app.rule'));
    }

    public function testOverridingTheMethodDropsTheAttribute()
    {
        $container = new ContainerBuilder();
        $container->register('app.overrides', OverridesMethod::class)->setAutoconfigured(true);

        $this->process($container);

        $this->assertFalse($container->has('app.overrides::evaluate'));
    }

    public function testAttributeIsInheritedFromTheInterface()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, RuleInterface::class);
        $container->register('app.foo_rule', FooRule::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.foo_rule::evaluate');

        $this->assertEquals([[new Reference('app.foo_rule'), 'evaluate']], $definition->getArguments());
        $this->assertSame([[]], $definition->getTag('app.rule'));
    }

    public function testTheInterfaceItselfGetsNoClosureService()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, RuleInterface::class);

        $this->process($container);

        $this->assertFalse($container->has(RuleInterface::class.'::evaluate'));
    }

    public function testInterfacesWithoutADefinitionAreIgnored()
    {
        $container = new ContainerBuilder();
        $container->register('app.foo_rule', FooRule::class)->setAutoconfigured(true);

        $this->process($container);

        $this->assertFalse($container->has('app.foo_rule::evaluate'));
    }

    public function testAttributeOnTheClassWinsOverTheInterface()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, RuleInterface::class);
        $container->register('app.bar_rule', BarRule::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.bar_rule::evaluate');

        $this->assertSame([], $definition->getTag('app.rule'));
        $this->assertSame([[]], $definition->getTag('app.other_rule'));
    }

    #[DataProvider('provideInterfaceOrders')]
    public function testTheSubInterfaceWinsOverTheOneItExtends(array $interfaces)
    {
        $container = new ContainerBuilder();
        foreach ($interfaces as $interface) {
            $this->registerInterface($container, $interface);
        }
        $container->register('app.sub_rule', SubRule::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.sub_rule::evaluate');

        $this->assertSame([], $definition->getTag('app.rule'));
        $this->assertSame([[]], $definition->getTag('app.sub_rule'));
    }

    public static function provideInterfaceOrders(): iterable
    {
        yield 'extended interface first' => [[RuleInterface::class, SubRuleInterface::class]];
        yield 'sub-interface first' => [[SubRuleInterface::class, RuleInterface::class]];
    }

    public function testAbstractMethodsReachTheClassesImplementingThem()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, AbstractRule::class);
        $container->register('app.concrete_rule', ConcreteRule::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.concrete_rule::evaluate');

        $this->assertEquals([[new Reference('app.concrete_rule'), 'evaluate']], $definition->getArguments());
        $this->assertSame([['key' => 'concrete']], $definition->getTag('app.rule'));
    }

    public function testClassLevelAttributeTargetsInvoke()
    {
        $container = new ContainerBuilder();
        $container->register('app.invokable', Invokable::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.invokable::__invoke');

        $this->assertEquals([[new Reference('app.invokable'), '__invoke']], $definition->getArguments());
        $this->assertSame([[]], $definition->getTag('app.rule'));
    }

    public function testClassLevelAttributeOnAnInterface()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, InvokableInterface::class);
        $container->register('app.from_interface', InvokableFromInterface::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.from_interface::__invoke');

        $this->assertEquals([[new Reference('app.from_interface'), '__invoke']], $definition->getArguments());
        $this->assertSame([[]], $definition->getTag('app.rule'));
    }

    public function testClassLevelAttributeReachesSubclasses()
    {
        $container = new ContainerBuilder();
        $container->register(Invokable::class, Invokable::class)->setAutoconfigured(true);
        $container->register('app.child', InvokableChild::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.child::__invoke');

        $this->assertEquals([[new Reference('app.child'), '__invoke']], $definition->getArguments());
        $this->assertSame([[]], $definition->getTag('app.rule'));
    }

    public function testClassLevelAttributeIsIgnoredWhenTheParentHasNoDefinition()
    {
        $container = new ContainerBuilder();
        $container->register('app.child', InvokableChild::class)->setAutoconfigured(true);

        $this->process($container);

        $this->assertFalse($container->has('app.child::__invoke'));
    }

    public function testAMethodAttributeWinsOverTheClassAttributeItInherits()
    {
        $container = new ContainerBuilder();
        $container->register(Invokable::class, Invokable::class)->setAutoconfigured(true);
        $container->register('app.override', InvokableOverride::class)->setAutoconfigured(true);

        $this->process($container);

        $definition = $container->getDefinition('app.override::__invoke');

        $this->assertSame([], $definition->getTag('app.rule'));
        $this->assertSame([[]], $definition->getTag('app.other_rule'));
    }

    public function testTagDeclaredWithANameKey()
    {
        $container = new ContainerBuilder();
        $container->register('app.rules', Rules::class)->setAutoconfigured(true);

        $this->process($container);

        $this->assertSame([['priority' => -5]], $container->getDefinition('app.rules::isMinor')->getTag('app.rule'));
    }

    public function testTagAttributesComputedByACallable()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, CallableRuleInterface::class);
        $container->register('app.callable_rule', CallableRule::class)->setAutoconfigured(true);
        $container->register('app.other_callable_rule', OtherCallableRule::class)->setAutoconfigured(true);

        $this->process($container);

        $this->assertSame([['key' => 'callable']], $container->getDefinition('app.callable_rule::evaluate')->getTag('app.rule'));
        $this->assertSame([['key' => 'other callable']], $container->getDefinition('app.other_callable_rule::evaluate')->getTag('app.rule'));
    }

    #[RequiresPhp('>=8.5.0')]
    public function testTagAttributesComputedByAClosure()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, ClosureTagInterface::class);
        $container->register('app.closure_rule', ClosureTagRule::class)->setAutoconfigured(true);
        $container->register('app.other_closure_rule', OtherClosureTagRule::class)->setAutoconfigured(true);

        $this->process($container);

        $this->assertSame([['key' => 'closure']], $container->getDefinition('app.closure_rule::evaluate')->getTag('app.rule'));
        $this->assertSame([['key' => 'other closure']], $container->getDefinition('app.other_closure_rule::evaluate')->getTag('app.rule'));
    }

    public function testTheRelayTagIsRemovedFromEveryService()
    {
        $container = new ContainerBuilder();
        $this->registerInterface($container, RuleInterface::class);
        $container->register('app.rules', Rules::class)->setAutoconfigured(true);
        $container->register('app.foo_rule', FooRule::class)->setAutoconfigured(true);

        $this->process($container);

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->hasTag('container.excluded')) {
                continue;
            }

            $this->assertFalse($definition->hasTag(RegisterClosureServicesPass::TAG), \sprintf('Service "%s" still carries the relay tag.', $id));
        }
    }

    public function testTagsKeyedByNameAreRejected()
    {
        $container = new ContainerBuilder();
        $container->register('app.keyed', KeyedTags::class)->setAutoconfigured(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list of tags, as everywhere else tags are declared, not a map keyed by tag name.');

        $this->process($container);
    }

    public function testNonScalarTagAttributesAreRejected()
    {
        $container = new ContainerBuilder();
        $container->register('app.bad', BadAttributes::class)->setAutoconfigured(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "handler" attribute of the "app.rule" tag declared by "#[AsClosureService]" on "Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\BadAttributes::evaluate()" must be of a scalar type, "stdClass" given.');

        $this->process($container);
    }

    public function testNonPublicMethodsAreRejected()
    {
        $container = new ContainerBuilder();
        $container->register('app.hidden', NonPublicMethod::class)->setAutoconfigured(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "#[AsClosureService]" attribute cannot be used on the non-public method "Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\NonPublicMethod::hidden()".');

        $this->process($container);
    }

    public function testClassLevelAttributeWithoutInvokeIsRejected()
    {
        $container = new ContainerBuilder();
        $container->register('app.not_invokable', NotInvokable::class)->setAutoconfigured(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "#[AsClosureService]" attribute on "Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass\NotInvokable" requires a public "__invoke()" method. Declare it on the method to expose instead.');

        $this->process($container);
    }

    public function testCollidingIdsAreRejected()
    {
        $container = new ContainerBuilder();
        $container->register('app.rules', Rules::class)->setAutoconfigured(true);
        $container->register('app.rule.is_even', \stdClass::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot register the closure service "app.rule.is_even"');

        $this->process($container);
    }

    private function process(ContainerBuilder $container): void
    {
        (new RegisterAsClosureAttributesPass())->process($container);
        (new ResolveInstanceofConditionalsPass())->process($container);
        (new RegisterClosureServicesPass())->process($container);
    }

    /**
     * Mirrors what FileLoader::registerClasses() registers for a discovered interface.
     */
    private function registerInterface(ContainerBuilder $container, string $interface): void
    {
        $container->register($interface, $interface)
            ->setAbstract(true)
            ->setAutoconfigured(true)
            ->addTag('container.excluded', ['source' => 'because the class is abstract']);
    }
}
