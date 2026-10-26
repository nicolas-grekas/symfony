<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\EventListener\EnvelopeListener;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EnvelopeListenerTest extends TestCase
{
    public function testSenderTakesTheNameOfTheFromAddress()
    {
        $message = (new Email())->from('Fabien <fabien@example.com>', 'Other <other@example.com>');

        $this->assertEquals(new Address('fabien@example.com', 'Fabien'), $this->getSender('fabien@example.com', $message));
    }

    public function testSenderTakesTheNameOfTheSenderAddress()
    {
        $message = (new Email())->from('Other <other@example.com>')->sender('Fabien <fabien@example.com>');

        $this->assertEquals(new Address('fabien@example.com', 'Fabien'), $this->getSender('fabien@example.com', $message));
    }

    public function testSenderKeepsItsOwnName()
    {
        $message = (new Email())->from('Fabien <fabien@example.com>');

        $this->assertEquals(new Address('fabien@example.com', 'Envelope'), $this->getSender('Envelope <fabien@example.com>', $message));
    }

    public function testSenderIgnoresNamesOfOtherAddresses()
    {
        $message = (new Email())->from('Fabien <fabien@example.com>');

        $this->assertEquals(new Address('bounces@example.com'), $this->getSender('bounces@example.com', $message));
    }

    public function testSenderHeaderIsAddedWhenMissing()
    {
        $message = (new Email())->to('to@example.com');

        $this->assertEquals(new Address('fabien@example.com', 'Fabien'), $this->getSender('Fabien <fabien@example.com>', $message));
        $this->assertEquals(new Address('fabien@example.com', 'Fabien'), $message->getSender());
    }

    private function getSender(string $sender, Email $message): Address
    {
        $envelope = new Envelope(new Address('original@example.com'), [new Address('to@example.com')]);

        (new EnvelopeListener($sender))->onMessage(new MessageEvent($message, $envelope, 'default'));

        return $envelope->getSender();
    }
}
