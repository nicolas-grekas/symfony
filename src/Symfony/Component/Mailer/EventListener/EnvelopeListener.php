<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message;

/**
 * Manipulates the Envelope of a Message.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class EnvelopeListener implements EventSubscriberInterface
{
    private ?Address $sender = null;

    /**
     * @var Address[]|null
     */
    private ?array $recipients = null;

    /**
     * @param array<Address|string> $recipients
     */
    public function __construct(Address|string|null $sender = null, ?array $recipients = null)
    {
        if (null !== $sender) {
            $this->sender = Address::create($sender);
        }
        if (null !== $recipients) {
            $this->recipients = Address::createArray($recipients);
        }
    }

    public function onMessage(MessageEvent $event): void
    {
        if ($this->sender) {
            $event->getEnvelope()->setSender($this->sender);

            $message = $event->getMessage();
            if ($message instanceof Message) {
                if (!$message->getHeaders()->has('Sender') && !$message->getHeaders()->has('From')) {
                    $message->getHeaders()->addMailboxHeader('Sender', $this->sender);
                } elseif ('' === $this->sender->getName() && $sender = $this->findNamedSender($message->getHeaders())) {
                    $event->getEnvelope()->setSender($sender);
                }
            }
        }

        if ($this->recipients) {
            $event->getEnvelope()->setRecipients($this->recipients);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // should be the last one to allow header changes by other listeners first
            MessageEvent::class => ['onMessage', -255],
        ];
    }

    private function findNamedSender(Headers $headers): ?Address
    {
        $addresses = $headers->get('From')?->getAddresses() ?? [];
        if ($sender = $headers->get('Sender')) {
            array_unshift($addresses, $sender->getAddress());
        }

        foreach ($addresses as $address) {
            if ('' !== $address->getName() && $address->getAddress() === $this->sender->getAddress()) {
                return $address;
            }
        }

        return null;
    }
}
