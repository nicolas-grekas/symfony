<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Test;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnIdleListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * Assertions and helpers for the messages queued on in-memory Messenger transports.
 */
trait MessengerAssertionsTrait
{
    /**
     * Asserts the number of messages queued on an in-memory transport.
     *
     * @param string|null $messageClass Counts only the messages that are instances of this class
     */
    public static function assertQueuedMessageCount(int $count, string $transport, ?string $messageClass = null, string $message = ''): void
    {
        $envelopes = self::getQueuedMessages($transport);

        if (null !== $messageClass) {
            $envelopes = array_filter($envelopes, static fn (Envelope $envelope) => $envelope->getMessage() instanceof $messageClass);
        }

        self::assertCount($count, $envelopes, $message ?: \sprintf('Failed asserting that the "%s" transport has %d queued message(s)%s.', $transport, $count, null === $messageClass ? '' : ' of class "'.$messageClass.'"'));
    }

    /**
     * Returns the envelopes queued on an in-memory transport, delayed ones included.
     *
     * @return Envelope[]
     */
    public static function getQueuedMessages(string $transport): array
    {
        $receiver = self::getMessengerTransport($transport);

        if (!class_exists(StopWorkerOnIdleListener::class)) {
            throw new \LogicException('Listing queued messages requires symfony/messenger 8.2 or higher.');
        }

        return $receiver->all();
    }

    /**
     * Handles the messages queued on an in-memory transport with the message bus of the application.
     *
     * The worker listeners of the application run as in production: a message whose handler
     * fails is sent for retry or to the failure transport as configured. The first failure is
     * rethrown after the run, so that a failing handler fails the test.
     *
     * @param int|null $limit Stops after this number of messages, instead of draining the queue
     */
    public static function consumeQueuedMessages(string $transport, ?int $limit = null): void
    {
        if (!class_exists(StopWorkerOnIdleListener::class)) {
            throw new \LogicException('Consuming queued messages requires symfony/messenger 8.2 or higher.');
        }

        $container = static::getContainer();
        $receiver = self::getMessengerTransport($transport);
        $bus = $container->get('messenger.routable_message_bus');
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');

        $failures = [];
        $recordFailure = static function (WorkerMessageFailedEvent $event) use (&$failures): void {
            $failures[] = $event->getThrowable();
        };
        $subscribers = [new StopWorkerOnIdleListener()];

        if (null !== $limit) {
            $subscribers[] = new StopWorkerOnMessageLimitListener($limit);
        }

        foreach ($subscribers as $subscriber) {
            $dispatcher->addSubscriber($subscriber);
        }
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $recordFailure);

        try {
            (new Worker([$transport => $receiver], $bus, $dispatcher))->run(['sleep' => 0]);
        } finally {
            foreach ($subscribers as $subscriber) {
                $dispatcher->removeSubscriber($subscriber);
            }
            $dispatcher->removeListener(WorkerMessageFailedEvent::class, $recordFailure);
        }

        if ($failures) {
            throw $failures[0];
        }
    }

    /**
     * Returns the in-memory transport registered under the given name.
     */
    public static function getMessengerTransport(string $transport): InMemoryTransport
    {
        $container = static::getContainer();

        if (!$container->has($id = 'messenger.transport.'.$transport)) {
            static::fail(\sprintf('The "%s" Messenger transport is not registered. Did you forget to configure it under "framework.messenger.transports"?', $transport));
        }

        if (!($service = $container->get($id)) instanceof InMemoryTransport) {
            static::fail(\sprintf('The "%s" Messenger transport is not an in-memory transport. Configure "in-memory://" as its DSN in the test environment to make queued message assertions.', $transport));
        }

        return $service;
    }
}
