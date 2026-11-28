<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Dumper\Domain;

/**
 * A reference from a service to a service of another domain.
 *
 * @internal
 */
final class ServiceReference
{
    public function __construct(
        public readonly string $consumer,
        public readonly string $service,
        public readonly bool $allowed,
    ) {
    }
}
