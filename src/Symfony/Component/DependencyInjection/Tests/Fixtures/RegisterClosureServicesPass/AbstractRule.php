<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Fixtures\RegisterClosureServicesPass;

use Symfony\Component\DependencyInjection\Attribute\AsClosureService;

abstract class AbstractRule
{
    #[AsClosureService(tags: [['app.rule' => [self::class, 'tagAttributes']]])]
    abstract public function evaluate(int $value): bool;

    abstract public static function tagAttributes(): array;
}
