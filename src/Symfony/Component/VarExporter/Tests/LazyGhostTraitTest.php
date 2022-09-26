<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarExporter\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\VarExporter\Internal\LazyObjectRegistry;
use Symfony\Component\VarExporter\Tests\Fixtures\LazyGhost\ChildMagicClass;
use Symfony\Component\VarExporter\Tests\Fixtures\LazyGhost\ChildStdClass;
use Symfony\Component\VarExporter\Tests\Fixtures\LazyGhost\ChildTestClass;
use Symfony\Component\VarExporter\Tests\Fixtures\LazyGhost\LazyClass;
use Symfony\Component\VarExporter\Tests\Fixtures\LazyGhost\MagicClass;
use Symfony\Component\VarExporter\Tests\Fixtures\LazyGhost\TestClass;

class LazyGhostTraitTest extends TestCase
{
    public function testGetPublic()
    {
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $ghost) {
            $ghost->__construct();
        });

        $this->assertSame(["\0".TestClass::class."\0lazyObjectId"], array_keys((array) $instance));
        $this->assertSame(-4, $instance->public);
        $this->assertSame(4, $instance->publicReadonly);
    }

    public function testIssetPublic()
    {
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $ghost) {
            $ghost->__construct();
        });

        $this->assertSame(["\0".TestClass::class."\0lazyObjectId"], array_keys((array) $instance));
        $this->assertTrue(isset($instance->public));
        $this->assertSame(4, $instance->publicReadonly);
    }

    public function testUnsetPublic()
    {
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $ghost) {
            $ghost->__construct();
        });

        $this->assertSame(["\0".TestClass::class."\0lazyObjectId"], array_keys((array) $instance));
        unset($instance->public);
        $this->assertFalse(isset($instance->public));
        $this->assertSame(4, $instance->publicReadonly);
    }

    public function testSetPublic()
    {
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $ghost) {
            $ghost->__construct();
        });

        $this->assertSame(["\0".TestClass::class."\0lazyObjectId"], array_keys((array) $instance));
        $instance->public = 12;
        $this->assertSame(12, $instance->public);
        $this->assertSame(4, $instance->publicReadonly);
    }

    public function testClone()
    {
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $ghost) {
            $ghost->__construct();
        });

        $clone = clone $instance;

        $this->assertNotSame((array) $instance, (array) $clone);
        $this->assertSame(["\0".TestClass::class."\0lazyObjectId"], array_keys((array) $instance));
        $this->assertSame(["\0".TestClass::class."\0lazyObjectId"], array_keys((array) $clone));

        $clone = clone $clone;
        $this->assertTrue($clone->resetLazyObject());
    }

    public function testSerialize()
    {
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $ghost) {
            $ghost->__construct();
        });

        $serialized = serialize($instance);
        $this->assertStringNotContainsString('lazyObjectId', $serialized);

        $clone = unserialize($serialized);
        $expected = (array) $instance;
        $this->assertArrayHasKey("\0".TestClass::class."\0lazyObjectId", $expected);
        unset($expected["\0".TestClass::class."\0lazyObjectId"]);
        $this->assertSame(array_keys($expected), array_keys((array) $clone));
        $this->assertFalse($clone->resetLazyObject());
        $this->assertTrue($clone->isLazyObjectInitialized());
    }

    /**
     * @dataProvider provideMagicClass
     */
    public function testMagicClass(MagicClass $instance)
    {
        $this->assertSame('bar', $instance->foo);
        $instance->foo = 123;
        $this->assertSame(123, $instance->foo);
        $this->assertTrue(isset($instance->foo));
        unset($instance->foo);
        $this->assertFalse(isset($instance->foo));

        $clone = clone $instance;
        $this->assertSame(0, $instance->cloneCounter);
        $this->assertSame(1, $clone->cloneCounter);

        $instance->bar = 123;
        $serialized = serialize($instance);
        $clone = unserialize($serialized);
        $this->assertSame(123, $clone->bar);
    }

    public function provideMagicClass()
    {
        yield [new MagicClass()];

        yield [ChildMagicClass::createLazyGhost(function (ChildMagicClass $instance) {
            $instance->__construct();
        })];
    }

    public function testDestruct()
    {
        $registryCount = \count(LazyObjectRegistry::$states);
        $destructCounter = MagicClass::$destructCounter;

        $instance = ChildMagicClass::createLazyGhost(function (ChildMagicClass $instance) {
            $instance->__construct();
        });

        unset($instance);
        $this->assertSame($destructCounter, MagicClass::$destructCounter);

        $instance = ChildMagicClass::createLazyGhost(function (ChildMagicClass $instance) {
            $instance->__construct();
        });
        $instance->initializeLazyObject();
        unset($instance);

        $this->assertSame(1 + $destructCounter, MagicClass::$destructCounter);

        $this->assertCount($registryCount, LazyObjectRegistry::$states);
    }

    public function testResetLazyGhost()
    {
        $instance = ChildMagicClass::createLazyGhost(function (ChildMagicClass $instance) {
            $instance->__construct();
        });

        $instance->foo = 234;
        $this->assertTrue($instance->resetLazyObject());
        $this->assertFalse($instance->isLazyObjectInitialized());
        $this->assertSame('bar', $instance->foo);
    }

    public function testFullInitialization()
    {
        $counter = 0;
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $ghost) use (&$counter) {
            ++$counter;
            $ghost->__construct();
        });

        $this->assertFalse($instance->isLazyObjectInitialized());
        $this->assertTrue(isset($instance->public));
        $this->assertTrue($instance->isLazyObjectInitialized());
        $this->assertSame(-4, $instance->public);
        $this->assertSame(4, $instance->publicReadonly);
        $this->assertSame(1, $counter);
    }

    public function testPartialInitialization()
    {
        $counter = 0;
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $instance, string $propertyName, ?string $propertyScope) use (&$counter) {
            ++$counter;

            return match ($propertyName) {
                'public' => 123,
                'publicReadonly' => 234,
                'protected' => 345,
                'protectedReadonly' => 456,
                'private' => match ($propertyScope) {
                    TestClass::class => 567,
                    ChildTestClass::class => 678,
                },
            };
        });

        $this->assertSame(["\0".TestClass::class."\0lazyObjectId"], array_keys((array) $instance));
        $this->assertFalse($instance->isLazyObjectInitialized());
        $this->assertSame(123, $instance->public);
        $this->assertFalse($instance->isLazyObjectInitialized());
        $this->assertSame(["\0".TestClass::class."\0lazyObjectId", 'public'], array_keys((array) $instance));
        $this->assertSame(1, $counter);

        $instance->initializeLazyObject();
        $this->assertTrue($instance->isLazyObjectInitialized());
        $this->assertSame(123, $instance->public);
        $this->assertSame(6, $counter);

        $properties = (array) $instance;
        $this->assertIsInt($properties["\0".TestClass::class."\0lazyObjectId"]);
        unset($properties["\0".TestClass::class."\0lazyObjectId"]);
        $this->assertSame(array_keys((array) new ChildTestClass()), array_keys($properties));
        $this->assertSame([123, 345, 456, 567, 234, 678], array_values($properties));
    }

    public function testPartialInitializationWithReset()
    {
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $instance, string $propertyName, ?string $propertyScope) {
            return 234;
        });

        $r = new \ReflectionProperty($instance, 'public');
        $r->setValue($instance, 123);

        $this->assertFalse($instance->isLazyObjectInitialized());
        $this->assertSame(234, $instance->publicReadonly);
        $this->assertFalse($instance->isLazyObjectInitialized());
        $this->assertSame(123, $instance->public);

        $this->assertTrue($instance->resetLazyObject());
        $this->assertSame(234, $instance->publicReadonly);
        $this->assertSame(123, $instance->public);

        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $instance, string $propertyName, ?string $propertyScope) {
            return 234;
        });

        $instance->resetLazyObject();

        $instance->public = 123;
        $this->assertSame(123, $instance->public);

        $this->assertTrue($instance->resetLazyObject());
        $this->assertSame(234, $instance->public);
    }

    public function testPartialInitializationWithNastyPassByRef()
    {
        $instance = ChildTestClass::createLazyGhost(function (ChildTestClass $instance, string &$propertyName, ?string &$propertyScope) {
            return $propertyName = $propertyScope = 123;
        });

        $this->assertSame(123, $instance->public);
    }

    public function testSetStdClassProperty()
    {
        $instance = ChildStdClass::createLazyGhost(function (ChildStdClass $ghost) {
        });

        $instance->public = 12;
        $this->assertSame(12, $instance->public);
    }

    public function testLazyClass()
    {
        $obj = new LazyClass(fn ($proxy) => $proxy->public = 123);

        $this->assertSame(123, $obj->public);
    }
}
