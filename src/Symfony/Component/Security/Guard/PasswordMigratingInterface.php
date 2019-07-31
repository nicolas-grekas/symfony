<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Guard;

use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The interface for all "guard" authenticators to automatically hook-in the password migration process.
 *
 * @author Roland Franssen <franssen.roland@gmail.com>
 */
interface PasswordMigratingInterface
{
    /**
     * @param mixed The user credentials
     */
    public function migratePassword(UserInterface $user, $credentials, PasswordUpgraderInterface $passwordUpgrader, UserPasswordEncoderInterface $passwordEncoder);
}
