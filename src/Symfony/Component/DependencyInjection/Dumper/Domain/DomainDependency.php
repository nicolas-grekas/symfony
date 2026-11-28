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
 * The references from the services of a domain to the services of another domain.
 *
 * @internal
 */
final class DomainDependency
{
    /**
     * @param non-empty-list<ServiceReference> $references
     */
    public function __construct(
        public readonly string $consumer,
        public readonly string $provider,
        public readonly array $references,
    ) {
    }

    public function countViolations(): int
    {
        return \count(array_filter($this->references, static fn (ServiceReference $reference) => !$reference->allowed));
    }
}
