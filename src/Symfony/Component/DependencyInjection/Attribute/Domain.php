<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Attribute;

/**
 * An attribute to tell which domain a service belongs to.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Domain extends Autoconfigure
{
    /**
     * @param string $name       The name of the domain
     * @param bool   $exposed    Whether the services of other domains can reference this service
     * @param bool   $restricted Whether this service can only reference the services of this domain
     */
    public function __construct(string $name, bool $exposed = false, bool $restricted = false)
    {
        parent::__construct(tags: [['container.domain' => ['domain' => $name, 'exposed' => $exposed, 'restricted' => $restricted]]]);
    }
}
