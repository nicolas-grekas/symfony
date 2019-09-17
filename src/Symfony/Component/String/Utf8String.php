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

use Symfony\Component\String\Exception\ExceptionInterface;
use Symfony\Component\String\Exception\InvalidArgumentException;

/**
 * Represents a string of Unicode code points encoded as UTF-8.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 * @author Hugo Hamon <hugohamon@neuf.fr>
 *
 * @throws ExceptionInterface
 */
class Utf8String extends AbstractUnicodeString
{
    public function __construct(string $string = '')
    {
        if ('' !== $string && !preg_match('//u', $string)) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }

        $this->string = $string;
    }

    public function append(string ...$suffix): AbstractString
    {
        $str = clone $this;
        $str->string .= 1 >= \count($suffix) ? $suffix[0] ?? '' : implode('', $suffix);

        if (!preg_match('//u', $str->string)) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }

        return $str;
    }

    public function chunk(int $length = 1): array
    {
        if (1 > $length) {
            throw new InvalidArgumentException('The maximum length of each segment must be greater than zero.');
        }

        if ('' === $this->string) {
            return [];
        }

        $rx = '/(';
        while (65535 < $length) {
            $rx .= '.{65535}';
            $length -= 65535;
        }
        $rx .= '.{'.$length.'})/us';

        $chunks = [];

        foreach (preg_split($rx, $this->string, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $chunk) {
            $chunks[] = $str = clone $this;
            $str->string = $chunk;
        }

        return $chunks;
    }

    public function endsWith($suffix): bool
    {
        if ($suffix instanceof AbstractString) {
            $suffix = $suffix->string;
        } elseif (!\is_string($suffix)) {
            return parent::endsWith($suffix);
        }

        if ('' === $suffix || !preg_match('//u', $suffix)) {
            return false;
        }

        if ($this->ignoreCase) {
            return preg_match('{'.preg_quote($suffix).'$}iu', $this->string);
        }

        return \strlen($this->string) - \strlen($suffix) === strrpos($this->string, $suffix);
    }

    public function equalsTo($string): bool
    {
        if ($string instanceof AbstractString) {
            $string = $string->string;
        } elseif (!\is_string($string)) {
            return parent::equalsTo($string);
        }

        if ($this->ignoreCase) {
            return mb_strlen($string, 'UTF-8') === mb_strlen($this->string, 'UTF-8') && 0 === mb_stripos($this->string, $string, 'UTF-8');
        }

        return $string === $this->string;
    }

    public function indexOf($needle, int $offset = 0): ?int
    {
        if ($needle instanceof AbstractString) {
            $needle = $needle->string;
        } elseif (!\is_string($needle)) {
            return parent::indexOf($needle, $offset);
        }

        if ('' === $needle) {
            return null;
        }

        $i = $this->ignoreCase ? mb_stripos($this->string, $needle, $offset, 'UTF-8') : mb_strpos($this->string, $needle, $offset, 'UTF-8');

        return false === $i ? null : $i;
    }

    public function indexOfLast($needle, int $offset = 0): ?int
    {
        if ($needle instanceof AbstractString) {
            $needle = $needle->string;
        } elseif (!\is_string($needle)) {
            return parent::indexOfLast($needle, $offset);
        }

        if ('' === $needle) {
            return null;
        }

        $i = $this->ignoreCase ? mb_strripos($this->string, $needle, $offset, 'UTF-8') : mb_strrpos($this->string, $needle, $offset, 'UTF-8');

        return false === $i ? null : $i;
    }

    public function length(): int
    {
        return mb_strlen($this->string, 'UTF-8');
    }

    public function prepend(string ...$prefix): AbstractString
    {
        $str = clone $this;
        $str->string = (1 >= \count($prefix) ? $prefix[0] ?? '' : implode('', $prefix)).$this->string;

        if (!preg_match('//u', $str->string)) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }

        return $str;
    }

    public function replace(string $from, string $to): AbstractString
    {
        $str = clone $this;

        if ('' === $from || !preg_match('//u', $from)) {
            return $str;
        }

        if ('' !== $to && !preg_match('//u', $to)) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }

        if ($this->ignoreCase) {
            $str->string = implode($to, preg_split('{'.preg_quote($from).'}iu', $this->string));
        } else {
            $str->string = str_replace($from, $to, $this->string);
        }

        return $str;
    }

    public function slice(int $start = 0, int $length = null): AbstractString
    {
        $str = clone $this;
        $str->string = (string) mb_substr($this->string, $start, $length, 'UTF-8');

        return $str;
    }

    public function splice(string $replacement, int $start = 0, int $length = null): AbstractString
    {
        if (!preg_match('//u', $replacement)) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }

        $str = clone $this;
        $start = $start ? \strlen(mb_substr($this->string, 0, $start, 'UTF-8')) : 0;
        $length = $length ? \strlen(mb_substr($this->string, $start, $length, 'UTF-8')) : $length;
        $str->string = substr_replace($this->string, $replacement, $start, $length ?? \PHP_INT_MAX);

        return $str;
    }

    public function split(string $delimiter, int $limit = null): array
    {
        if ('' === $delimiter) {
            throw new InvalidArgumentException('Split delimiter is empty.');
        }

        if (!preg_match('//u', $delimiter)) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }

        $chunks = $this->ignoreCase
            ? preg_split('{'.preg_quote($delimiter).'}iu', $this->string, $limit ?? \PHP_INT_MAX)
            : explode($delimiter, $this->string, $limit ?? \PHP_INT_MAX);

        foreach ($chunks as $i => $chunk) {
            $chunks[$i] = $str = clone $this;
            $str->string = $chunk;
        }

        return $chunks;
    }

    public function startsWith($prefix): bool
    {
        if ($prefix instanceof AbstractString) {
            $prefix = $prefix->string;
        } elseif (!\is_string($prefix)) {
            return parent::startsWith($prefix);
        }

        if ('' === $prefix || !preg_match('//u', $prefix)) {
            return false;
        }

        return 0 === ($this->ignoreCase ? mb_stripos($this->string, $prefix, 0, 'UTF-8') : strpos($this->string, $prefix));
    }
}
