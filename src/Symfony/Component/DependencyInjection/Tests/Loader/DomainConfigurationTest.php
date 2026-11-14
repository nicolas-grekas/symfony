<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Loader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class DomainConfigurationTest extends TestCase
{
    #[DataProvider('provideLoaders')]
    public function testDomainConfiguration(string $loaderClass, string $file)
    {
        $container = new ContainerBuilder();
        $loader = new $loaderClass($container, new FileLocator(__DIR__.'/../Fixtures'));
        $loader->load($file);

        $container->compile();

        $this->assertInstanceOf(\stdClass::class, $container->get('application')[0]);
        $this->assertSame($container->get('application')[0], $container->get('migration')[0]);
    }

    public static function provideLoaders(): iterable
    {
        yield [YamlFileLoader::class, 'domain_configuration.yml'];
        yield [PhpFileLoader::class, 'domain_configuration.php'];
    }

    public function testAliasAttributesCanExposeAndGrantASpecificAlias()
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Fixtures'));
        $loader->load('domain_aliases.yml');

        $container->compile();

        $this->assertInstanceOf(\IteratorAggregate::class, $container->get('migration')[0]);
    }
}
