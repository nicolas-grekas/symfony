<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Tests\Fixtures\Messenger\DummyMessage;
use Symfony\Bundle\FrameworkBundle\Tests\Fixtures\Messenger\FailingDummyMessageHandler;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Event\SyncMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class MessengerSyncTransportTest extends AbstractWebTestCase
{
    public function testSyncTransportRetriesThenSendsToTheFailureTransport()
    {
        if (!class_exists(SyncMessageFailedEvent::class)) {
            $this->markTestSkipped('This test requires symfony/messenger 8.2 or higher.');
        }

        $container = self::getContainer();
        FailingDummyMessageHandler::$calls = 0;

        $envelope = $container->get(MessageBusInterface::class)->dispatch(new DummyMessage('Hey'));

        $this->assertSame(3, FailingDummyMessageHandler::$calls);
        $this->assertSame('sync_with_retry', $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());

        $failureTransport = $container->get('failed_transport');
        $this->assertInstanceOf(InMemoryTransport::class, $failureTransport);
        $this->assertCount(1, $failureTransport->getSent());
        $failed = $failureTransport->getSent()[0];
        $this->assertInstanceOf(DummyMessage::class, $failed->getMessage());
        $this->assertSame('sync_with_retry', $failed->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
        $this->assertSame('Handling "Hey" failed 3 time(s).', $failed->last(ErrorDetailsStamp::class)?->getExceptionMessage());
        $this->assertCount(3, $failed->all(RedeliveryStamp::class));
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['test_case' => 'MessengerSyncTransport'] + $options);
    }
}
