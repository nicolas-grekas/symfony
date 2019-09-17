<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\String\Tests;

use Symfony\Component\String\AbstractString;
use Symfony\Component\String\Utf8String;

class Utf8StringTest extends AbstractUtf8TestCase
{
    protected static function createFromString(string $string): AbstractString
    {
        return new Utf8String($string);
    }

    public static function provideLength(): array
    {
        return array_merge(
            parent::provideLength(),
            [
                // 8 instead of 5 if it were processed as a grapheme cluster
                [8, 'अनुच्छेद'],
            ]
        );
    }
}
