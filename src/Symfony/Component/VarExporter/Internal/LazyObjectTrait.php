<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarExporter\Internal;

/**
 * @internal
 */
trait LazyObjectTrait
{
    /**
     * Returns whether the object is initialized.
     *
     * @param $partial Whether partially initialized objects should be considered as initialized
     */
    public function isLazyObjectInitialized(bool $partial = false): bool
    {
        return !\ReflectionLazyObject::isLazyObject($this);
    }

    /**
     * @return bool Returns false when the object cannot be reset, ie when it's not a lazy object
     */
    public function resetLazyObject(): bool
    {
        if (\ReflectionLazyObject::isLazyObject($this)) {
            return true;
        }

        if (![$initializer, $strategy, $skippedProperties] = LazyObjectRegistry::$initializers[$this] ?? null) {
            return false;
        }

        $r = \ReflectionLazyObject::makeLazy($this, $initializer, $strategy);

        foreach ($skippedProperties as $class => $properties) {
            foreach ($properties as $property) {
                $r->skipProperty($property, $class);
            }
        }

        return true;
    }
}
