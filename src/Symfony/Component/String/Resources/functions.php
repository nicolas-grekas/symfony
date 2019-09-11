<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\String;

function g(string $string = ''): GraphemeString
{
    return new GraphemeString($string);
}

function u(string $string = ''): Utf8String
{
    return new Utf8String($string);
}

function b(string $string = ''): BinaryString
{
    return new BinaryString($string);
}
