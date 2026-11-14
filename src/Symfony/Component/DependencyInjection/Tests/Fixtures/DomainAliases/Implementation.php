<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures\DomainAliases;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Domain;

#[Domain('billing')]
#[AsAlias('domain.api', exposed: true, allowedDomains: ['migrations'])]
class Implementation implements \IteratorAggregate
{
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator([]);
    }
}
