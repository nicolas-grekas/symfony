<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ChainStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Dispatches the next message of a ChainStamp once the current message is handled.
 *
 * The next message is dispatched with a DispatchAfterCurrentBusStamp: it is
 * handled after the current message, outside a transaction opened for it,
 * and in a worker, after the handlers of the current message returned.
 * The remaining messages travel with it in a new ChainStamp, so a chain
 * continues from where it stopped when a failed step is retried.
 *
 * When an envelope carries several ChainStamps, their messages form one
 * sequence, in stamp order.
 *
 * This middleware must run after the SendMessageMiddleware, so that a
 * message sent to a transport does not start the next step before being
 * handled.
 */
final class ChainMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $envelope = $stack->next()->handle($envelope, $stack);

        $messages = [];
        foreach ($envelope->all(ChainStamp::class) as $stamp) {
            $messages = [...$messages, ...$stamp->getMessages()];
        }

        if (!$messages) {
            return $envelope;
        }

        $next = Envelope::wrap(array_shift($messages), [new DispatchAfterCurrentBusStamp()]);

        if ($messages) {
            $next = $next->with(new ChainStamp(...$messages));
        }

        if (null === $next->last(BusNameStamp::class) && null !== $busNameStamp = $envelope->last(BusNameStamp::class)) {
            $next = $next->with($busNameStamp);
        }

        $this->bus->dispatch($next);

        return $envelope;
    }
}
