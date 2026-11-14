<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Domain extends Autoconfigure
{
    public function __construct(string $name, bool $exposed = false, bool $restricted = false)
    {
        parent::__construct(tags: [['container.domain' => ['domain' => $name, 'exposed' => $exposed, 'restricted' => $restricted]]]);
    }
}
