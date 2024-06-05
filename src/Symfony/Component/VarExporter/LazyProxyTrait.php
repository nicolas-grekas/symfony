<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarExporter;

use Symfony\Component\VarExporter\Internal\LazyObjectRegistry;
use Symfony\Component\VarExporter\Internal\LazyObjectTrait;

trait LazyProxyTrait
{
    use LazyObjectTrait;

    /**
     * Creates a lazy-loading virtual proxy.
     *
     * @param \Closure():object $initializer Returns the proxied object
     * @param static|null       $instance
     */
    public static function createLazyProxy(\Closure $initializer, ?object $instance = null): static
    {
        $instance ??= (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();

        $wrapperInitializer = function ($instance) use ($initializer, &$wrapperInitializer) {
            $initializersRegistry = LazyObjectRegistry::$initializers ??= new \WeakMap();
            $initializersRegistry[$instance] = [$wrapperInitializer, \ReflectionLazyObject::STRATEGY_VIRTUAL, []];

            return $initializer($instance);
        };

        \ReflectionLazyObject::makeLazy($instance, $wrapperInitializer, \ReflectionLazyObject::STRATEGY_VIRTUAL);

        return $instance;
    }

    /**
     * Forces initialization of a lazy object and returns it.
     */
    public function initializeLazyObject(): parent
    {
        return \ReflectionLazyObject::fromInstance($this)->initialize();
    }

}
