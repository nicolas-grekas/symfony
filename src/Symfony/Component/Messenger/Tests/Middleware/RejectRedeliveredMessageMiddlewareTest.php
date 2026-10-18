<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Tests\Middleware;

use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineReceivedStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RejectRedeliveredMessageException;
use Symfony\Component\Messenger\Middleware\RejectRedeliveredMessageMiddleware;
use Symfony\Component\Messenger\Test\Middleware\MiddlewareTestCase;
use Symfony\Component\Messenger\Tests\Fixtures\DummyMessage;

class RejectRedeliveredMessageMiddlewareTest extends MiddlewareTestCase
{
    public function testItRejectsTheMessagesRedeliveredByDoctrine()
    {
        $envelope = new Envelope(new DummyMessage('Hi'), [new DoctrineReceivedStamp('1', true)]);

        $this->expectException(RejectRedeliveredMessageException::class);

        (new RejectRedeliveredMessageMiddleware())->handle($envelope, $this->getStackMock(false));
    }

    public function testItHandlesTheMessagesDeliveredByDoctrineForTheFirstTime()
    {
        $envelope = new Envelope(new DummyMessage('Hi'), [new DoctrineReceivedStamp('1')]);

        $this->assertSame($envelope, (new RejectRedeliveredMessageMiddleware())->handle($envelope, $this->getStackMock()));
    }
}
