<?php

namespace Symfony\Bundle\FrameworkBundle\Tests\Fixtures;

class DomainInternal implements \Stringable
{
    public function __toString(): string
    {
        return 'billing';
    }
}
