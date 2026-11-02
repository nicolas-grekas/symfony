<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\PropertyInfo\Tests\Fixtures;

class DummyNestedUnionType
{
    /**
     * @var (int|string)|null
     */
    public $orNull;

    /**
     * @var ?(int|string)
     */
    public $nullable;

    /**
     * @var int|(string|(bool|null))
     */
    public $deep;

    /**
     * @var array<(int|string)>
     */
    public $collection;

    /**
     * @var ((\Traversable&\Countable)|string)|null
     */
    public $intersection;
}
