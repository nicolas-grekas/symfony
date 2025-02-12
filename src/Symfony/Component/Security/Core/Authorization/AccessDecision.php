<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Core\Authorization;

/**
 * Contains the access verdict and all the related votes.
 *
 * @author Dany Maillard <danymaillard93b@gmail.com>
 * @author Roman JOLY <eltharin18@outlook.fr>
 */
final class AccessDecision
{
    public bool $isGranted;

    /**
     * @var Vote[]
     */
    public $votes = [];

    public function __construct(
        public readonly bool $allowMultipleAttributes = false,
    ) {
    }
}
