<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

class ReferenceTest extends TestCase
{
    public function testConstructor()
    {
        $ref = new Reference('foo');
        $this->assertEquals('foo', (string) $ref, '__construct() sets the id of the reference, which is used for the __toString() method');
    }

    public function testSerializationPreservesDomainAllowances()
    {
        foreach ([null, 'billing'] as $domain) {
            $reference = new Reference('foo', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $domain);
            $copy = unserialize(serialize($reference), ['allowed_classes' => [Reference::class]]);

            $this->assertSame($domain, $copy->getAllowedDomain());
            $this->assertSame(serialize($reference), serialize($copy));
        }
    }
}
