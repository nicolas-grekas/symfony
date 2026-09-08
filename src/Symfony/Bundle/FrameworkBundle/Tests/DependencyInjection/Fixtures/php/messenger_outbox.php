<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$container->loadFromExtension('framework', [
    'messenger' => [
        'transports' => [
            'orders' => [
                'dsn' => 'amqp://localhost/%2f/orders',
                'outbox' => 'outbox',
            ],
            'outbox' => 'doctrine://default?queue_name=outbox',
        ],
    ],
]);
