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

use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Tests\Fixtures\Messenger\BarMessage;
use Symfony\Bundle\FrameworkBundle\Tests\Fixtures\Messenger\FooMessage;
use Symfony\Bundle\FrameworkBundle\Tests\Fixtures\Messenger\RecordingMessageHandler;
use Symfony\Bundle\FrameworkBundle\Tests\Fixtures\Messenger\SecondMessage;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnIdleListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class MessengerTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        RecordingMessageHandler::$handled = [];
        RecordingMessageHandler::$exception = null;
    }

    public function testQueuedMessagesAreConsumedThroughTheBus()
    {
        if (!class_exists(StopWorkerOnIdleListener::class)) {
            $this->markTestSkipped('This test requires symfony/messenger 8.2 or higher.');
        }

        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch($foo = new FooMessage());
        $bus->dispatch($bar = new BarMessage());

        $this->assertInstanceOf(InMemoryTransport::class, $this->getMessengerTransport('async'));
        $this->assertQueuedMessageCount(2, 'async');
        $this->assertQueuedMessageCount(1, 'async', FooMessage::class);
        $this->assertQueuedMessageCount(0, 'async', SecondMessage::class);
        $this->assertSame([$foo, $bar], array_map(static fn (Envelope $envelope) => $envelope->getMessage(), $this->getQueuedMessages('async')));
        $this->assertSame([], RecordingMessageHandler::$handled);

        $this->consumeQueuedMessages('async');

        $this->assertQueuedMessageCount(0, 'async');
        $this->assertSame([], $this->getQueuedMessages('async'));
        $this->assertSame([$foo, $bar], RecordingMessageHandler::$handled);

        $this->consumeQueuedMessages('async');

        $this->assertSame([$foo, $bar], RecordingMessageHandler::$handled, 'Consuming an empty queue returns without handling anything');
    }

    public function testConsumeQueuedMessagesWithLimit()
    {
        if (!class_exists(StopWorkerOnIdleListener::class)) {
            $this->markTestSkipped('This test requires symfony/messenger 8.2 or higher.');
        }

        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch($foo1 = new FooMessage());
        $bus->dispatch($foo2 = new FooMessage());
        $bus->dispatch($foo3 = new FooMessage());

        $this->consumeQueuedMessages('async', 2);

        $this->assertQueuedMessageCount(1, 'async');
        $this->assertSame([$foo1, $foo2], RecordingMessageHandler::$handled);

        $this->consumeQueuedMessages('async', 2);

        $this->assertQueuedMessageCount(0, 'async');
        $this->assertSame([$foo1, $foo2, $foo3], RecordingMessageHandler::$handled);
    }

    public function testConsumeQueuedMessagesRethrowsHandlerFailures()
    {
        if (!class_exists(StopWorkerOnIdleListener::class)) {
            $this->markTestSkipped('This test requires symfony/messenger 8.2 or higher.');
        }

        RecordingMessageHandler::$exception = new \RuntimeException('Handling failed.');
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new FooMessage());

        try {
            $this->consumeQueuedMessages('async');
            $this->fail('The handler failure should have been rethrown.');
        } catch (HandlerFailedException $e) {
            $this->assertSame([RecordingMessageHandler::$exception], array_values($e->getWrappedExceptions()));
        }

        // the retry listener sent the message back to the transport with a delay
        $this->assertQueuedMessageCount(1, 'async');
        $this->assertSame(1, $this->getQueuedMessages('async')[0]->last(RedeliveryStamp::class)?->getRetryCount());
        $this->assertSame([], RecordingMessageHandler::$handled);
    }

    public function testGetMessengerTransportRequiresAnInMemoryTransport()
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The "sync" Messenger transport is not an in-memory transport. Configure "in-memory://" as its DSN in the test environment to make queued message assertions.');

        $this->getMessengerTransport('sync');
    }

    public function testGetMessengerTransportRequiresAConfiguredTransport()
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The "unknown" Messenger transport is not registered. Did you forget to configure it under "framework.messenger.transports"?');

        $this->getMessengerTransport('unknown');
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['test_case' => 'Messenger'] + $options);
    }
}
