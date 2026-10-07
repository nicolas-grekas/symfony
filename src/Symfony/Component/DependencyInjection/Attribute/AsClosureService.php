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
 * An attribute to expose a public method of a service as a closure service.
 *
 * On a class or an interface, it stands for the __invoke() method.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class AsClosureService
{
    /**
     * @param array<array<mixed>>|string[] $tags The tags to add to the closure service. A tag's attributes may be a
     *                                           \Closure or a [class-string, method] callable that computes them from
     *                                           the class-string of the service declaring the method
     * @param string|null                  $id   The id of the closure service, defaulting to "<service id>::<method>"
     * @param bool                         $lazy Whether to instantiate the service declaring the method only when the
     *                                           closure is first called. Ignored for static methods
     */
    public function __construct(
        public array $tags = [],
        public ?string $id = null,
        public bool $lazy = true,
    ) {
    }
}
