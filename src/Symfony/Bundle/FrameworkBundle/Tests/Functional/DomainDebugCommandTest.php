<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Tests\Fixtures\DomainInternal;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandCompletionTester;

#[Group('functional')]
class DomainDebugCommandTest extends AbstractWebTestCase
{
    #[DataProvider('provideDebugModes')]
    public function testContainerListsMembersAndGrants(bool $debug)
    {
        $tester = $this->createTester($debug);
        $tester->run(['command' => 'debug:container', '--domain' => 'migrations', '--format' => 'json'], ['interactive' => false]);

        $data = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('migration', $data['definitions']);
        $this->assertArrayHasKey(\Stringable::class, $data['aliases']);
        $this->assertArrayNotHasKey(DomainInternal::class, $data['definitions']);
        $this->assertSame(['migrations'], $data['aliases'][\Stringable::class]['allowed_domains']);
    }

    public static function provideDebugModes(): iterable
    {
        yield [true];
        yield [false];
    }

    public function testContainerReportsExplicitExceptions()
    {
        $tester = $this->createTester();
        $tester->run(['command' => 'debug:container', '--domain' => 'billing']);

        $this->assertStringContainsString('Domain dependencies', $tester->getDisplay());
        $this->assertStringContainsString('exception_consumer', $tester->getDisplay());
        $this->assertStringContainsString('reference', $tester->getDisplay());
    }

    public function testAutowiringHidesInternalTypesOutsideTheirDomain()
    {
        $tester = $this->createTester();
        $tester->run(['command' => 'debug:autowiring', '--all' => true]);

        $this->assertStringContainsString('Stringable', $tester->getDisplay());
        $this->assertDoesNotMatchRegularExpression('/^\s*'.preg_quote(DomainInternal::class, '/').'\s*$/m', $tester->getDisplay());
    }

    public function testAutowiringUsesTheConsumersDomain()
    {
        $tester = $this->createTester();
        $tester->run(['command' => 'debug:autowiring', '--for' => 'billing_consumer', '--all' => true]);

        $this->assertStringContainsString(DomainInternal::class, $tester->getDisplay());
    }

    public function testAutowiringRespectsRestrictedConsumers()
    {
        $tester = $this->createTester();
        $tester->run(['command' => 'debug:autowiring', '--for' => 'migration', '--all' => true]);

        $this->assertStringContainsString('Stringable', $tester->getDisplay());
        $this->assertDoesNotMatchRegularExpression('/^\s*'.preg_quote(DomainInternal::class, '/').'\s*$/m', $tester->getDisplay());
        $this->assertStringNotContainsString('stdClass', $tester->getDisplay());
    }

    public function testDomainCompletion()
    {
        static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => 'domains.yml']);
        $application = new Application(static::$kernel);
        $tester = new CommandCompletionTester($application->find('debug:container'));

        $this->assertSame(['billing', 'migrations'], $tester->complete(['--domain', '']));
    }

    #[DataProvider('provideDomainFormats')]
    public function testDomainMetadataAndExceptionsAreAvailableInEveryFormat(string $format, string $grant, string $exception)
    {
        $tester = $this->createTester();
        $tester->run(['command' => 'debug:container', '--domain' => 'billing', '--format' => $format], ['interactive' => false]);

        $this->assertStringContainsString($grant, $tester->getDisplay());
        $this->assertStringContainsString($exception, $tester->getDisplay());
    }

    public static function provideDomainFormats(): iterable
    {
        yield ['txt', 'Allowed domains', 'exception_consumer'];
        yield ['json', '"allowed_domains"', '"exception_consumer"'];
        yield ['md', '- Allowed domains: migrations', '`exception_consumer`'];
        yield ['xml', '<exposed-to domain="migrations"', 'consumer="exception_consumer"'];
    }

    public function testAutowiringCompletionUsesTheConsumersDomain()
    {
        static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => 'domains.yml']);
        $application = new Application(static::$kernel);
        $tester = new CommandCompletionTester($application->find('debug:autowiring'));

        $suggestions = $tester->complete(['--for', 'migration', '']);

        $this->assertContains(\Stringable::class, $suggestions);
        $this->assertNotContains(DomainInternal::class, $suggestions);
        $this->assertNotContains(\stdClass::class, $suggestions);
    }

    private function createTester(bool $debug = true): ApplicationTester
    {
        static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => 'domains.yml', 'debug' => $debug]);
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return new ApplicationTester($application);
    }
}
