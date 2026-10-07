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

class BadAttributes
{
    #[AsClosureService(tags: [['app.rule' => [self::class, 'attributes']]])]
    public function evaluate(): bool
    {
        return true;
    }

    public static function attributes(): array
    {
        return ['handler' => new \stdClass()];
    }
}
