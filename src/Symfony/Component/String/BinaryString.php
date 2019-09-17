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
 * Represents a binary-safe string of bytes.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 * @author Hugo Hamon <hugohamon@neuf.fr>
 *
 * @throws ExceptionInterface
 */
class BinaryString extends AbstractString
{
    public function __construct(string $string = '')
    {
        $this->string = $string;
    }

    public static function fromRandom(int $length = 16): self
    {
        $string = '';

        do {
            $string .= str_replace(['/', '+', '='], '', base64_encode(random_bytes($length)));
        } while (\strlen($string) < $length);

        return new static(substr($string, 0, $length));
    }

    public function byteCode(int $offset = 0): ?int
    {
        $str = $offset ? $this->slice($offset, 1) : $this;

        return '' === $str->string ? null : \ord($str->string);
    }

    public function append(string ...$suffix): parent
    {
        $str = clone $this;
        $str->string .= 1 >= \count($suffix) ? $suffix[0] ?? '' : implode('', $suffix);

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

        $chunks = [];

        foreach (str_split($this->string, $length) as $chunk) {
            $chunks[] = $str = clone $this;
            $str->string = $chunk;
        }

        return $chunks;
    }

    public function endsWith($suffix): bool
    {
        if ($suffix instanceof parent) {
            $suffix = $suffix->string;
        } elseif (!\is_string($suffix)) {
            return parent::endsWith($suffix);
        }

        return \strlen($this->string) - \strlen($suffix) === ($this->ignoreCase ? strripos($this->string, $suffix) : strrpos($this->string, $suffix));
    }

    public function equalsTo($string): bool
    {
        if ($string instanceof parent) {
            $string = $string->string;
        } elseif (!\is_string($string)) {
            return parent::equalsTo($string);
        }

        if ($this->ignoreCase) {
            return 0 === strcasecmp($string, $this->string);
        }

        return $string === $this->string;
    }

    public function folded(): parent
    {
        $str = clone $this;
        $str->string = strtolower($str->string);

        return $str;
    }

    public function indexOf($needle, int $offset = 0): ?int
    {
        if ($needle instanceof parent) {
            $needle = $needle->string;
        } elseif (!\is_string($needle)) {
            return parent::indexOf($needle, $offset);
        }

        if ('' === $needle) {
            return null;
        }

        $i = $this->ignoreCase ? stripos($this->string, $needle, $offset) : strpos($this->string, $needle, $offset);

        return false === $i ? null : $i;
    }

    public function indexOfLast($needle, int $offset = 0): ?int
    {
        if ($needle instanceof parent) {
            $needle = $needle->string;
        } elseif (!\is_string($needle)) {
            return parent::indexOf($needle, $offset);
        }

        if ('' === $needle) {
            return null;
        }

        $i = $this->ignoreCase ? strripos($this->string, $needle, $offset) : strrpos($this->string, $needle, $offset);

        return false === $i ? null : $i;
    }

    public function isUtf8(): bool
    {
        return '' === $this->string || preg_match('//u', $this->string);
    }

    public function join(array $strings): parent
    {
        $str = clone $this;
        $str->string = implode($str->string, $strings);

        return $str;
    }

    public function length(): int
    {
        return \strlen($this->string);
    }

    public function lower(): parent
    {
        $str = clone $this;
        $str->string = strtolower($str->string);

        return $str;
    }

    public function match(string $pattern, int $flags = 0, int $offset = 0): array
    {
        if ('g' === $pattern[-1]) {
            $pattern = substr($pattern, 0, -1);
            $match = 'preg_match_all';
        } else {
            $match = 'preg_match';
        }

        if ($this->ignoreCase) {
            $pattern .= 'i';
        }

        set_error_handler(static function ($t, $m) { throw new InvalidArgumentException($m); });

        try {
            if (false === $match($pattern, $this->string, $matches, $flags, $offset)) {
                $lastError = preg_last_error();

                foreach (get_defined_constants(true)['pcre'] as $k => $v) {
                    if ($lastError === $v && '_ERROR' === substr($k, -6)) {
                        throw new RuntimeException('Matching failed with '.$k.'.');
                    }
                }

                throw new RuntimeException('Matching failed with unknown error code.');
            }
        } finally {
            restore_error_handler();
        }

        return $matches;
    }

    public function padBoth(int $length, string $padStr = ' '): parent
    {
        $str = clone $this;
        $str->string = str_pad($this->string, $length, $padStr, STR_PAD_BOTH);

        return $str;
    }

    public function padEnd(int $length, string $padStr = ' '): parent
    {
        $str = clone $this;
        $str->string = str_pad($this->string, $length, $padStr, STR_PAD_RIGHT);

        return $str;
    }

    public function padStart(int $length, string $padStr = ' '): parent
    {
        $str = clone $this;
        $str->string = str_pad($this->string, $length, $padStr, STR_PAD_LEFT);

        return $str;
    }

    public function prepend(string ...$prefix): parent
    {
        $str = clone $this;
        $str->string = (1 >= \count($prefix) ? $prefix[0] ?? '' : implode('', $prefix)).$str->string;

        return $str;
    }

    public function replace(string $from, string $to): parent
    {
        $str = clone $this;

        if ('' !== $from) {
            $str->string = $this->ignoreCase ? str_ireplace($from, $to, $this->string) : str_replace($from, $to, $this->string);
        }

        return $str;
    }

    public function replaceMatches(string $fromPattern, $to): parent
    {
        if ('g' === $fromPattern[-1]) {
            $fromPattern = substr($fromPattern, 0, -1);
        }

        if ($this->ignoreCase) {
            $fromPattern .= 'i';
        }

        if (\is_array($to)) {
            if (!\is_callable($to)) {
                throw new \TypeError(sprintf('Argument 2 passed to %s::replaceMatches() must be callable, array given.', \get_class($this)));
            }

            $replace = 'preg_replace_callback';
        } else {
            $replace = $to instanceof \Closure ? 'preg_replace_callback' : 'preg_replace';
        }

        set_error_handler(static function ($t, $m) { throw new InvalidArgumentException($m); });

        try {
            if (null === $string = $replace($fromPattern, $to, $this->string)) {
                $lastError = preg_last_error();

                foreach (get_defined_constants(true)['pcre'] as $k => $v) {
                    if ($lastError === $v && '_ERROR' === substr($k, -6)) {
                        throw new RuntimeException('Matching failed with '.$k.'.');
                    }
                }

                throw new RuntimeException('Matching failed with unknown error code.');
            }
        } finally {
            restore_error_handler();
        }

        $str = clone $this;
        $str->string = $string;

        return $str;
    }

    public function slice(int $start = 0, int $length = null): parent
    {
        $str = clone $this;
        $str->string = (string) substr($this->string, $start, $length ?? \PHP_INT_MAX);

        return $str;
    }

    public function splice(string $replacement, int $start = 0, int $length = null): parent
    {
        $str = clone $this;
        $str = substr_replace($this->string, $replacement, $start, $length ?? \PHP_INT_MAX);

        return $str;
    }

    public function split(string $delimiter, int $limit = null): array
    {
        if ('' === $delimiter) {
            throw new InvalidArgumentException('Split delimiter is empty.');
        }

        $chunks = $this->ignoreCase
            ? preg_split('{'.preg_quote($delimiter).'}i', $this->string, $limit ?? \PHP_INT_MAX)
            : explode($delimiter, $this->string, $limit ?? \PHP_INT_MAX);

        foreach ($chunks as $i => $chunk) {
            $chunks[$i] = $str = clone $this;
            $str->string = $chunk;
        }

        return $chunks;
    }

    public function startsWith($prefix): bool
    {
        if ($prefix instanceof parent) {
            $prefix = $prefix->string;
        } elseif (!\is_string($prefix)) {
            return parent::startsWith($prefix);
        }

        return '' !== $prefix && 0 === ($this->ignoreCase ? stripos($this->string, $prefix) : strpos($this->string, $prefix));
    }

    public function title(bool $allWords = false): parent
    {
        $str = clone $this;
        $str->string = $allWords ? ucwords($str->string) : ucfirst($str->string);

        return $str;
    }

    public function toUtf8(string $fromEncoding = null): Utf8String
    {
        $u = new Utf8String();

        if (null === $fromEncoding && preg_match('//u', $this->string)) {
            $u->string = $this->string;

            return $u;
        }

        set_error_handler(static function ($t, $m) { throw new InvalidArgumentException($m); });

        try {
            try {
                $u->string = mb_convert_encoding($this->string, 'UTF-8', $fromEncoding ?? 'Windows-1252');
            } catch (InvalidArgumentException $e) {
                if (!\function_exists('iconv')) {
                    throw $e;
                }

                $u->string = iconv($fromEncoding ?? 'Windows-1252', 'UTF-8', $this->string);
            }
        } finally {
            restore_error_handler();
        }

        return $u;
    }

    public function toGrapheme(string $fromEncoding = null): GraphemeString
    {
        $g = new GraphemeString();
        $g->string = $this->toUtf8($fromEncoding)->string;

        return $g;
    }

    public function trim(string $chars = " \t\n\r\0\x0B\x0C"): parent
    {
        $str = clone $this;
        $str->string = trim($str->string, $chars);

        return $str;
    }

    public function trimEnd(string $chars = " \t\n\r\0\x0B\x0C"): parent
    {
        $str = clone $this;
        $str->string = rtrim($str->string, $chars);

        return $str;
    }

    public function trimStart(string $chars = " \t\n\r\0\x0B\x0C"): parent
    {
        $str = clone $this;
        $str->string = ltrim($str->string, $chars);

        return $str;
    }

    public function upper(): parent
    {
        $str = clone $this;
        $str->string = strtoupper($str->string);

        return $str;
    }

    public function width(bool $ignoreAnsiDecoration = true): int
    {
        $width = 0;
        $s = str_replace(["\x00", "\x05", "\x07"], '', $this->string);

        if (false !== strpos($s, "\r")) {
            $s = str_replace(["\r\n", "\r"], "\n", $s);
        }

        foreach (explode("\n", $s) as $s) {
            if ($ignoreAnsiDecoration) {
                $s = preg_replace('/\x1B(?:
                    \[ [\x30-\x3F]*+ [\x20-\x2F]*+ [0x40-\x7E]
                    | [P\]X^_] .*? \x1B\\\\
                    | [\x41-\x7E]
                )/x', '', $s);
            }

            $w = substr_count($s, "\xAD") - substr_count($s, "\x08");

            if ($width < $w += \strlen($s)) {
                $width = $w;
            }
        }

        return $width;
    }
}
