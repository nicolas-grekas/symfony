<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Bridge\Doctrine\Transport;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * @author Vincent Touzet <vincent.touzet@gmail.com>
 */
class DoctrineReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        private string $id,
        private bool $redelivery = false,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Tells whether the message was fetched again because an earlier delivery was neither acknowledged nor rejected within the redeliver timeout.
     */
    public function isRedelivery(): bool
    {
        return $this->redelivery;
    }
}
