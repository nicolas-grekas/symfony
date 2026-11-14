<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

return App::config([
    'services' => [
        'internal' => [
            'class' => \stdClass::class,
            'tags' => [['container.domain' => ['domain' => 'billing']]],
        ],
        'api' => [
            'alias' => 'internal',
            'exposed' => true,
            'allowed_domains' => ['migrations'],
        ],
        'application' => [
            'class' => \ArrayObject::class,
            'public' => true,
            'arguments' => [[new Reference('internal', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, 'billing')]],
        ],
        'migration' => [
            'class' => \ArrayObject::class,
            'public' => true,
            'tags' => [['container.domain' => ['domain' => 'migrations', 'restricted' => true]]],
            'arguments' => [['@api']],
        ],
    ],
]);
