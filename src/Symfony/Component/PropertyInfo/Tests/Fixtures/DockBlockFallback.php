<?php

namespace Symfony\Component\PropertyInfo\Tests\Fixtures;

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\PropertyInfo\Tests\Fixtures;

/**
 * PhpDocExtractor should fallback from property -> accessor -> mutator when looking up dockblocks.
 *
 * @author Martin Rademacher <mano@radebatz.net>
 */
class DockBlockFallback
{
    /** @var string $pub */
    public $pub = 'pub';

    protected $protAcc;
    protected $protMut;

    public function getPub()
    {
        return $this->pub;
    }

    public function setPub(string $pub)
    {
        $this->pub = $pub;
    }

    /**
     * @return int
     */
    public function getProtAcc(): int
    {
        return $this->protAcc;
    }

    public function setProt(int $protAcc)
    {
        $this->protAcc = $protAcc;
    }

    public function getProtMut()
    {
        return $this->protMut;
    }

    public function setProtMut(bool $protMut)
    {
        $this->protMut = $protMut;
    }
}
