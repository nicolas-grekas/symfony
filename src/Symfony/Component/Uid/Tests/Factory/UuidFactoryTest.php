<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Uid\Tests\Factory;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\Factory\UuidFactory;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\UuidV1;
use Symfony\Component\Uid\UuidV3;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV5;
use Symfony\Component\Uid\UuidV6;

final class UuidFactoryTest extends TestCase
{
    public function testCreateNamedDefaultVersion()
    {
        $this->assertInstanceOf(UuidV5::class, (new UuidFactory())->nameBased('6f80c216-0492-4421-bd82-c10ab929ae84')->create('foo'));
        $this->assertInstanceOf(UuidV3::class, (new UuidFactory(6, 6, 3))->nameBased('6f80c216-0492-4421-bd82-c10ab929ae84')->create('foo'));
    }

    public function testCreateNamed()
    {
        $uuidFactory = new UuidFactory();

        // Test custom namespace
        $uuid1 = $uuidFactory->nameBased('6f80c216-0492-4421-bd82-c10ab929ae84')->create('foo');
        $this->assertInstanceOf(UuidV5::class, $uuid1);
        $this->assertSame('d521ceb7-3e31-5954-b873-92992c697ab9', (string) $uuid1);

        // Test default namespace override
        $uuid2 = $uuidFactory->nameBased(Uuid::v4())->create('foo');
        $this->assertFalse($uuid1->equals($uuid2));

        // Test version override
        $uuidFactory = new UuidFactory(6, 6, 3, 4, new NilUuid(), '6f80c216-0492-4421-bd82-c10ab929ae84');
        $uuid3 = $uuidFactory->nameBased()->create('foo');
        $this->assertInstanceOf(UuidV3::class, $uuid3);
    }

    public function testCreateTimedDefaultVersion()
    {
        $this->assertInstanceOf(UuidV6::class, (new UuidFactory())->timeBased()->create());
        $this->assertInstanceOf(UuidV1::class, (new UuidFactory(6, 1))->timeBased()->create());
    }

    public function testCreateTimed()
    {
        $uuidFactory = new UuidFactory(6, 6, 5, 4, '6f80c216-0492-4421-bd82-c10ab929ae84');

        // Test custom timestamp
        $uuid1 = $uuidFactory->timeBased()->create(new \DateTime('@1611076938.057800'));
        $this->assertInstanceOf(UuidV6::class, $uuid1);
        $this->assertSame('1611076938.057800', $uuid1->getDateTime()->format('U.u'));
        $this->assertSame('c10ab929ae84', $uuid1->getNode());

        // Test default node override
        $uuid2 = $uuidFactory->timeBased('7c1ede70-3586-48ed-a984-23c8018d9174')->create();
        $this->assertInstanceOf(UuidV6::class, $uuid2);
        $this->assertSame('23c8018d9174', $uuid2->getNode());

        // Test version override
        $uuid3 = (new UuidFactory(6, 1))->timeBased()->create();
        $this->assertInstanceOf(UuidV1::class, $uuid3);

        // Test negative timestamp and round
        $uuid4 = $uuidFactory->timeBased()->create(new \DateTime('@-12219292800'));
        $this->assertSame('-12219292800.000000', $uuid4->getDateTime()->format('U.u'));
    }

    public function testInvalidCreateTimed()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The provided UUID timestamp must be higher than 1582-10-15.');

        (new UuidFactory())->timeBased()->create(new \DateTime('@-12219292800.001000'));
    }

    public function testCreateRandom()
    {
        $this->assertInstanceOf(UuidV4::class, (new UuidFactory())->randomBased()->create());
    }
}
