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

use Symfony\Component\VarExporter\Hydrator as PublicHydrator;

/**
 * Keeps the state of lazy objects.
 *
 * As a micro-optimization, this class uses no type declarations.
 *
 * @internal
 */
class LazyObjectState
{
    public const STATUS_INITIALIZED_PARTIAL = 1;
    public const STATUS_UNINITIALIZED_FULL = 2;
    public const STATUS_INITIALIZED_FULL = 3;

    /**
     * @var array<class-string|'*', array<string, true>>
     */
    public array $preInitUnsetProperties;

    /**
     * @var array<string, true>
     */
    public array $preInitSetProperties;

    /**
     * @var array<class-string|'*', array<string, true>>
     */
    public array $unsetProperties;

    /**
     * @var array<string, true>
     */
    public array $skippedProperties;

    /**
     * @var self::STATUS_*
     */
    public int $status = 0;

    public function __construct(public \Closure $initializer, $skippedProperties = [])
    {
        $this->skippedProperties = $this->preInitSetProperties = $skippedProperties;
    }

    /**
     * @return bool Returns true when fully-initializing, false when partial-initializing
     */
    public function initialize($instance, $propertyName, $propertyScope)
    {
        if (!$this->status) {
            $this->status = 1 < (new \ReflectionFunction($this->initializer))->getNumberOfRequiredParameters() ? self::STATUS_INITIALIZED_PARTIAL : self::STATUS_UNINITIALIZED_FULL;
            $this->preInitUnsetProperties = $this->unsetProperties ??= [];

            if (\count($this->preInitSetProperties) !== \count($properties = $this->preInitSetProperties + (array) $instance)) {
                $this->preInitSetProperties = array_fill_keys(array_keys($properties), true);
            }

            if (null === $propertyName) {
                return self::STATUS_INITIALIZED_PARTIAL !== $this->status;
            }
        }

        if (self::STATUS_INITIALIZED_FULL === $this->status) {
            return true;
        }

        if (self::STATUS_INITIALIZED_PARTIAL === $this->status) {
            $value = ($this->initializer)(...[$instance, $propertyName, $propertyScope]);

            $propertyScope ??= $instance::class;
            $accessor = LazyObjectRegistry::$classAccessors[$propertyScope] ??= LazyObjectRegistry::getClassAccessors($propertyScope);

            $accessor['set']($instance, $propertyName, $value);

            return false;
        }

        $this->status = self::STATUS_INITIALIZED_FULL;

        try {
            if ($defaultProperties = array_diff_key(LazyObjectRegistry::$defaultProperties[$instance::class], $this->preInitSetProperties)) {
                PublicHydrator::hydrate($instance, $defaultProperties);
            }

            ($this->initializer)($instance);
        } catch (\Throwable $e) {
            $this->status = self::STATUS_UNINITIALIZED_FULL;

            if ($defaultProperties) {
                $propertyScopes = Hydrator::$propertyScopes[$class = $instance::class];
                $skippedProperties = [];
                foreach ($propertyScopes as $key => [$scope, $name]) {
                    $propertyScopes[$k = "\0$scope\0$name"] ?? $propertyScopes[$k = "\0*\0$name"] ?? $k = $name;

                    if ($k === $key && !\array_key_exists($k, $defaultProperties)) {
                        $skippedProperties[$key] = true;
                    }
                }

                foreach (LazyObjectRegistry::$classResetters[$class] as $reset) {
                    $reset($instance, $skippedProperties);
                }
            }

            throw $e;
        }

        return true;
    }
}
