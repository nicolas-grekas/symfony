<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures\Domain;

use Symfony\Component\DependencyInjection\Attribute\Domain;

#[Domain('billing')]
class Implementation implements Contract
{
}
