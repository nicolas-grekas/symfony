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

class SnakeCaseDummy
{
    private string $snake_property;
    private string $snake_readOnly;
    private string $snake_method;
    private $snake_date;
    private $snake_count;
    private $snake_items = [];
    private $snake_flag;

    public function getSnakeProperty()
    {
        return $this->snake_property;
    }

    public function getSnakeReadOnly()
    {
        return $this->snake_readOnly;
    }

    public function setSnakeProperty($snake_property)
    {
        $this->snake_property = $snake_property;
    }

    public function setSnake_method($snake_method)
    {
        $this->snake_method = $snake_method;
    }

    public function setSnakeDate(\DateTimeInterface $snake_date)
    {
        $this->snake_date = $snake_date;
    }

    public function getSnakeCount(): int
    {
        return $this->snake_count;
    }

    public function addSnakeItem(\DateTime $snake_item)
    {
        $this->snake_items[] = $snake_item;
    }

    public function removeSnakeItem(\DateTime $snake_item)
    {
    }

    public function setSnake_flag(bool $snake_flag)
    {
        $this->snake_flag = $snake_flag;
    }
}
