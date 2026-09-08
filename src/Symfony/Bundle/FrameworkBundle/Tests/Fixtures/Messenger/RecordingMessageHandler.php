<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Fixtures\Messenger;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RecordingMessageHandler
{
    public static array $handled = [];
    public static ?\Throwable $exception = null;

    public function __invoke(FooMessage|BarMessage $message): void
    {
        if (self::$exception) {
            throw self::$exception;
        }

        self::$handled[] = $message;
    }
}
