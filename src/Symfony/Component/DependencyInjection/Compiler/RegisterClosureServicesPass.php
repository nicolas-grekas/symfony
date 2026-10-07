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
use Symfony\Component\DependencyInjection\Reference;

/**
 * Turns the "container.closure_service" tag recorded by {@see RegisterAsClosureAttributesPass} into a closure
 * service per attributed method, then removes it.
 */
final class RegisterClosureServicesPass implements CompilerPassInterface
{
    use LazyTagAttributesTrait;

    /**
     * @internal
     */
    public const TAG = 'container.closure_service';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $definition = $container->getDefinition($id);
            $definition->clearTag(self::TAG);

            if ($definition->isAbstract() || !($class = $container->getReflectionClass($definition->getClass(), false))) {
                continue;
            }

            foreach (self::declaringClasses($tags) as $method => $declaredBy) {
                if (!$class->hasMethod($method) || !($r = $container->getReflectionClass($declaredBy, false)) || !$r->hasMethod($method)) {
                    continue;
                }

                $attributes = $r->getMethod($method)->getAttributes(AsClosureService::class, \ReflectionAttribute::IS_INSTANCEOF)
                    // a type-level attribute stands for the __invoke() it was recorded under
                    ?: $r->getAttributes(AsClosureService::class, \ReflectionAttribute::IS_INSTANCEOF);

                if ($attributes) {
                    $this->register($container, $id, $class, $method, $class->getMethod($method)->isStatic(), $attributes[0]->newInstance());
                }
            }
        }
    }

    /**
     * Picks, for each attributed method of a service, the type whose #[AsClosureService] applies.
     *
     * What the class declares on the method itself wins, being tagged directly rather than inherited
     * from a rule. Among the rules, the first tag wins, ResolveInstanceofConditionalsPass adding them
     * from the most specific type to the least.
     *
     * @param array<array<mixed>> $tags
     *
     * @return array<string, string> Method name => the type declaring the attribute
     */
    private static function declaringClasses(array $tags): array
    {
        $declared = [];

        foreach ($tags as $tag) {
            if (!($tag['inherited'] ?? false)) {
                $declared[$tag['method']] = $tag['declared_by'];
            }
        }

        // Only the methods the class doesn't declare itself are left to the types it inherits from
        foreach ($tags as $tag) {
            $declared[$tag['method']] ??= $tag['declared_by'];
        }

        return $declared;
    }

    private function register(ContainerBuilder $container, string $serviceId, \ReflectionClass $class, string $method, bool $static, AsClosureService $attribute): void
    {
        $id = $attribute->id ?? $serviceId.'::'.$method;

        if ($container->has($id)) {
            throw new InvalidArgumentException(\sprintf('Cannot register the closure service "%s" declared by "#[AsClosureService]" on "%s::%s()" because a service with that id already exists.', $id, $class->name, $method));
        }

        $closureService = $container->register($id, 'Closure')
            ->setFactory(['Closure', 'fromCallable'])
            ->setArguments([$static ? [$class->name, $method] : [new Reference($serviceId), $method]])
            ->setLazy($attribute->lazy && !$static);

        if (!array_is_list($attribute->tags)) {
            throw new InvalidArgumentException(\sprintf('The "tags" of "#[AsClosureService]" on "%s::%s()" must be a list of tags, as everywhere else tags are declared, not a map keyed by tag name.', $class->name, $method));
        }

        foreach ($attribute->tags as $tag) {
            [$name, $tagAttributes] = self::resolveTag($tag, $class->name, $method);

            $closureService->addTag($name, $tagAttributes);
        }
    }

    /**
     * Turns a tag declared by the attribute into a [name, attributes] pair, accepting the shapes the
     * YAML format accepts, and resolving the attribute-sets computed from the declaring class-string.
     *
     * @return array{string, array<mixed>}
     */
    private static function resolveTag(mixed $tag, string $class, string $method): array
    {
        if (!\is_array($tag)) {
            $tag = ['name' => $tag];
        }

        // Mirrors ContentLoaderTrait::parseDefinition(), except that a closure is also a valid
        // attribute-set here, where it cannot be expressed in YAML
        if (1 === \count($tag) && (\is_array($attributes = current($tag)) || $attributes instanceof \Closure)) {
            $name = key($tag);
        } else {
            if (!isset($tag['name'])) {
                throw new InvalidArgumentException(\sprintf('A tag declared by "#[AsClosureService]" on "%s::%s()" is missing a "name" key.', $class, $method));
            }

            $name = $tag['name'];
            unset($tag['name']);
            $attributes = $tag;
        }

        if (!\is_string($name) || '' === $name) {
            throw new InvalidArgumentException(\sprintf('The tag name declared by "#[AsClosureService]" on "%s::%s()" must be a non-empty string.', $class, $method));
        }

        if ($attributes instanceof \Closure) {
            $attributes = [$attributes];
        }

        if (self::isLazyTagAttributes($attributes)) {
            $attributes = self::resolveTagAttributes($attributes, $class, $name, \sprintf('%s::%s()', $class, $method));
        }

        self::assertScalarAttributes($attributes, $name, $class, $method);

        return [$name, $attributes];
    }

    /**
     * Like the validation the loaders apply to the tags they read, so that a value no tag can carry is
     * reported where it is written instead of much later.
     *
     * @param array<mixed> $attributes
     * @param list<string> $path
     */
    private static function assertScalarAttributes(array $attributes, string $tag, string $class, string $method, array $path = []): void
    {
        foreach ($attributes as $key => $value) {
            if (\is_array($value)) {
                self::assertScalarAttributes($value, $tag, $class, $method, [...$path, $key]);
            } elseif (!\is_scalar($value ?? '')) {
                throw new InvalidArgumentException(\sprintf('The "%s" attribute of the "%s" tag declared by "#[AsClosureService]" on "%s::%s()" must be of a scalar type, "%s" given%s.', implode('.', [...$path, $key]), $tag, $class, $method, get_debug_type($value), $value instanceof \Closure ? '. A closure computing the attributes replaces the whole attribute-set, it cannot be nested inside it' : ''));
            }
        }
    }
}
