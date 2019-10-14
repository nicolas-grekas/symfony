<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Secrets;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

/**
 * @author Tobias Schultze <http://tobion.de>
 */
class SecretEnvVarProcessor implements EnvVarProcessorInterface
{
    private $vault;

    public function __construct(SodiumVault $vault)
    {
        $this->vault = $vault;
    }

    /**
     * {@inheritdoc}
     */
    public static function getProvidedTypes()
    {
        return [
            'secret' => 'string',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getEnv($prefix, $name, \Closure $getEnv)
    {
        if (null !== $secret = $this->vault->reveal($name)) {
            return $secret;
        }

        throw new EnvNotFoundException(sprintf('Secret "%s" not found or decryption key is missing.', $name));
    }
}
