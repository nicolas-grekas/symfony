<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger;

use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DispatchOnFailureStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\FailedMessageStamp;

/**
 * @internal
 */
trait DispatchOnFailureTrait
{
    private MessageBusInterface $bus;

    /**
     * Dispatches the message named by the DispatchOnFailureStamp of a failed envelope.
     *
     * The failure message gets the message that failed, the error details recorded
     * on the failed envelope, or created from the throwable when there are none,
     * and the bus name of the failed envelope unless it names its own bus.
     */
    private function dispatchFailureMessage(Envelope $failedEnvelope, ?\Throwable $throwable): Envelope
    {
        $envelope = Envelope::wrap($failedEnvelope->last(DispatchOnFailureStamp::class)->getMessage(), [new FailedMessageStamp($failedEnvelope->getMessage())]);

        if (null !== $errorDetailsStamp = $failedEnvelope->last(ErrorDetailsStamp::class)) {
            $envelope = $envelope->with($errorDetailsStamp);
        } elseif (null !== $throwable) {
            $envelope = $envelope->with(ErrorDetailsStamp::create($throwable));
        }

        if (null === $envelope->last(BusNameStamp::class) && null !== $busNameStamp = $failedEnvelope->last(BusNameStamp::class)) {
            $envelope = $envelope->with($busNameStamp);
        }

        return $this->bus->dispatch($envelope);
    }
}
