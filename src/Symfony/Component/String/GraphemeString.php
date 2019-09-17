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
 * Represents a string of Unicode grapheme clusters encoded as UTF-8.
 *
 * A letter followed by combining characters (accents typically) forms what Unicode defines
 * as a grapheme cluster: a character as humans mean it in written texts. This class knows
 * about the concept and won't split a letter appart from its combining accents. It also
 * ensures all string comparisons happen on their canonically-composed representation,
 * ignoring e.g. the order in which accents are listed when a letter has many of them.
 *
 * @see https://unicode.org/reports/tr15/
 *
 * @author Nicolas Grekas <p@tchwork.com>
 * @author Hugo Hamon <hugohamon@neuf.fr>
 *
 * @throws ExceptionInterface
 */
class GraphemeString extends AbstractUnicodeString
{
    public function __construct(string $string = '')
    {
        $this->string = normalizer_is_normalized($string) ? $string : normalizer_normalize($string);

        if (false === $this->string) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }
    }

    public function append(string ...$suffix): AbstractString
    {
        $str = clone $this;
        $str->string = $this->string.(1 >= \count($suffix) ? $suffix[0] ?? '' : implode('', $suffix));
        normalizer_is_normalized($str->string) ?: $str->string = normalizer_normalize($str->string);

        if (false === $str->string) {
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
            $rx .= '\X{65535}';
            $length -= 65535;
        }
        $rx .= '\X{'.$length.'})/us';

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

        normalizer_is_normalized($suffix) ?: $suffix = normalizer_normalize($suffix);

        if ('' === $suffix || false === $suffix || false === $i = $this->ignoreCase ? grapheme_strripos($this->string, $suffix) : grapheme_strrpos($this->string, $suffix)) {
            return false;
        }

        return grapheme_strlen($this->string) - grapheme_strlen($suffix) === $i;
    }

    public function equalsTo($string): bool
    {
        if ($string instanceof AbstractString) {
            $string = $string->string;
        } elseif (!\is_string($string)) {
            return parent::equalsTo($string);
        }

        normalizer_is_normalized($string) ?: $string = normalizer_normalize($string);

        if (false !== $string && $this->ignoreCase) {
            return grapheme_strlen($string) === grapheme_strlen($this->string) && 0 === grapheme_stripos($this->string, $string);
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

        normalizer_is_normalized($needle) ?: $needle = normalizer_normalize($needle);

        if ('' === $needle || false === $needle) {
            return null;
        }

        $i = $this->ignoreCase ? grapheme_stripos($this->string, $needle, $offset) : grapheme_strpos($this->string, $needle, $offset);

        return false === $i ? null : $i;
    }

    public function indexOfLast($needle, int $offset = 0): ?int
    {
        if ($needle instanceof AbstractString) {
            $needle = $needle->string;
        } elseif (!\is_string($needle)) {
            return parent::indexOfLast($needle, $offset);
        }

        normalizer_is_normalized($needle) ?: $needle = normalizer_normalize($needle);

        if ('' === $needle || false === $needle) {
            return null;
        }

        $string = $this->string;

        if (0 > $offset) {
            // workaround https://bugs.php.net/74264
            if (0 > $offset += grapheme_strlen($needle)) {
                $string = grapheme_substr($string, 0, $offset);
            }
            $offset = 0;
        }

        $i = $this->ignoreCase ? grapheme_strripos($string, $needle, $offset) : grapheme_strrpos($string, $needle, $offset);

        return false === $i ? null : $i;
    }

    public function join(array $strings): AbstractString
    {
        $str = parent::join($strings);
        normalizer_is_normalized($str->string) ?: $str->string = normalizer_normalize($str->string);

        return $str;
    }

    public function length(): int
    {
        return grapheme_strlen($this->string);
    }

    /**
     * @return static
     */
    public function normalize(int $form = self::NFC): parent
    {
        if (\in_array($form, [self::NFD, self::NFKD])) {
            $this->ignoreCase = null;
        } elseif (!\in_array($form, [self::NFC, self::NFKC])) {
            throw new InvalidArgumentException('Unsupported normalization form.');
        }

        $str = clone $this;
        normalizer_is_normalized($str->string, $form) ?: $str->string = normalizer_normalize($str->string, $form);

        return $str;
    }

    public function prepend(string ...$prefix): AbstractString
    {
        $str = clone $this;
        $str->string = (1 >= \count($prefix) ? $prefix[0] ?? '' : implode('', $prefix)).$this->string;
        normalizer_is_normalized($str->string) ?: $str->string = normalizer_normalize($str->string);

        if (false === $str->string) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }

        return $str;
    }

    public function replace(string $from, string $to): AbstractString
    {
        $str = clone $this;
        normalizer_is_normalized($from) ?: $from = normalizer_normalize($from);

        if ('' !== $from && false !== $from) {
            $tail = $str->string;
            $result = '';
            $indexOf = $this->ignoreCase ? 'grapheme_stripos' : 'grapheme_strpos';

            while (false !== $i = $indexOf($tail, $from)) {
                $slice = grapheme_substr($tail, 0, $i);
                $result .= $slice.$to;
                $tail = substr($tail, \strlen($slice) + \strlen($from));
            }

            $str->string = $result .= $tail;
            normalizer_is_normalized($str->string) ?: $str->string = normalizer_normalize($str->string);

            if (false === $str->string) {
                throw new InvalidArgumentException('Invalid UTF-8 string.');
            }
        }

        return $str;
    }

    public function replaceMatches(string $fromPattern, $to): AbstractString
    {
        $str = parent::replaceMatches($fromPattern, $to);
        normalizer_is_normalized($str->string) ?: $str->string = normalizer_normalize($str->string);

        return $str;
    }

    public function slice(int $start = 0, int $length = null): AbstractString
    {
        $str = clone $this;
        $str->string = (string) grapheme_substr($this->string, $start, $length ?? \PHP_INT_MAX);

        return $str;
    }

    public function splice(string $replacement, int $start = 0, int $length = null): AbstractString
    {
        $str = clone $this;
        $start = $start ? \strlen(grapheme_substr($this->string, 0, $start)) : 0;
        $length = $length ? \strlen(grapheme_substr($this->string, $start, $length ?? \PHP_INT_MAX)) : $length;
        $str->string = substr_replace($this->string, $replacement, $start, $length ?? \PHP_INT_MAX);
        normalizer_is_normalized($str->string) ?: $str->string = normalizer_normalize($str->string);

        if (false === $str->string) {
            throw new InvalidArgumentException('Invalid UTF-8 string.');
        }

        return $str;
    }

    public function split(string $delimiter, int $limit = null): array
    {
        if ('' === $delimiter) {
            throw new InvalidArgumentException('Split delimiter is empty.');
        }

        $tail = $this->string;
        $limit = $limit ?? \PHP_INT_MAX;
        $chunks = [];
        normalizer_is_normalized($delimiter) ?: $delimiter = normalizer_normalize($delimiter);

        if (false !== $delimiter) {
            $indexOf = $this->ignoreCase ? 'grapheme_stripos' : 'grapheme_strpos';

            while (1 < $limit && false !== $i = $indexOf($tail, $delimiter)) {
                $chunks[] = $str = clone $this;
                $str->string = grapheme_substr($tail, 0, $i);
                $tail = substr($tail, \strlen($str->string) + \strlen($delimiter));
                --$limit;
            }
        }

        $chunks[] = $str = clone $this;
        $str->string = $tail;

        return $chunks;
    }

    public function startsWith($prefix): bool
    {
        if ($prefix instanceof AbstractString) {
            $prefix = $prefix->string;
        } elseif (!\is_string($prefix)) {
            return parent::startsWith($prefix);
        }

        normalizer_is_normalized($prefix) ?: $prefix = normalizer_normalize($prefix);

        return '' !== $prefix && false !== $prefix && 0 === ($this->ignoreCase ? grapheme_stripos($this->string, $prefix) : grapheme_strpos($this->string, $prefix));
    }

    public function __clone()
    {
        if (null === $this->ignoreCase) {
            normalizer_is_normalized($this->string) ?: $this->string = normalizer_normalize($this->string);
        }

        $this->ignoreCase = false;
    }
}
