<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Attribute\AsClosureService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Records the #[AsClosureService] attributes as the "container.closure_service" tag, on definitions
 * that are autoconfigured and don't have the "container.ignore_attributes" tag.
 *
 * What a type declares is recorded as an autoconfiguration rule, so that it reaches every service of
 * that type the way #[Autoconfigure] does, through {@see ResolveInstanceofConditionalsPass}. What a class
 * declares on a method of its own is tagged directly, so that a subclass overriding that method
 * without the attribute gets nothing, which is what reflection already says.
 *
 * {@see RegisterClosureServicesPass} turns the tag into the closure services and removes it.
 */
final class RegisterAsClosureAttributesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            if (!$definition->isAutoconfigured() || $definition->hasTag('container.ignore_attributes')) {
                continue;
            }

            if (!($r = $container->getReflectionClass($definition->getClass(), false))) {
                continue;
            }

            if ($r->getAttributes(AsClosureService::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                if (!$r->hasMethod('__invoke') || !$r->getMethod('__invoke')->isPublic()) {
                    throw new InvalidArgumentException(\sprintf('The "#[AsClosureService]" attribute on "%s" requires a public "__invoke()" method. Declare it on the method to expose instead.', $r->name));
                }

                $container->registerForAutoconfiguration($r->name)
                    ->addTag(RegisterClosureServicesPass::TAG, ['method' => '__invoke', 'declared_by' => $r->name, 'inherited' => true]);
            }

            foreach ($r->getMethods() as $method) {
                if ($method->isConstructor() || $method->isDestructor()) {
                    continue;
                }

                if (!$attributes = $method->getAttributes(AsClosureService::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                    continue;
                }

                if (!$method->isPublic()) {
                    throw new InvalidArgumentException(\sprintf('The "#[AsClosureService]" attribute cannot be used on the non-public method "%s::%s()".', $r->name, $method->name));
                }

                $tag = ['method' => $method->name, 'declared_by' => $method->getDeclaringClass()->name];

                if ($method->isAbstract()) {
                    $attribute = $attributes[0]->newInstance();

                    if (null !== $attribute->id || null !== $attribute->target) {
                        throw new InvalidArgumentException(\sprintf('The "id" and "target" options of "#[AsClosureService]" cannot be used on the abstract method "%s::%s()": every service implementing it would claim them.', $r->name, $method->name));
                    }

                    $container->registerForAutoconfiguration($r->name)
                        ->addTag(RegisterClosureServicesPass::TAG, $tag + ['inherited' => true]);
                } elseif (!$definition->isAbstract()) {
                    $definition->addTag(RegisterClosureServicesPass::TAG, $tag);
                }
            }
        }
    }
}
