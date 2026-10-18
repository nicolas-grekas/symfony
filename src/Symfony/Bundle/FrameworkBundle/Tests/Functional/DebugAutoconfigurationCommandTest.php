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
use Symfony\Bundle\FrameworkBundle\Command\DebugAutoconfigurationCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Tests\Fixtures\PercentTagged;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[Group('functional')]
class DebugAutoconfigurationCommandTest extends AbstractWebTestCase
{
    #[DataProvider('provideKernelOptions')]
    public function testBasicFunctionality(string $rootConfig, bool $debug)
    {
        static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => $rootConfig, 'debug' => $debug]);

        $tester = $this->runDebugAutoconfiguration([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay(true);
        $this->assertStringContainsString("Symfony\Component\Console\Command\Command\n   tag console.command\n   tag console.command.service_arguments\n", $display);
        $this->assertStringContainsString("Psr\Log\LoggerAwareInterface\n   call setLogger()\n", $display);
        $this->assertStringContainsString("Symfony\Contracts\Service\ResetInterface\n   tag kernel.reset\n", $display);
        $this->assertStringContainsString("\n ".AsEventListener::class."\n", $display);
    }

    public static function provideKernelOptions(): iterable
    {
        yield 'dumped container' => ['config.yml', true];
        yield 'no dump' => ['no_dump.yml', true];
        yield 'no debug' => ['config.yml', false];
    }

    public function testSearchArgument()
    {
        static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => 'config.yml', 'debug' => true]);

        $display = $this->runDebugAutoconfiguration(['search' => 'reset'])->getDisplay();

        $this->assertStringContainsString('Symfony\Contracts\Service\ResetInterface', $display);
        $this->assertStringNotContainsString('Symfony\Component\Console\Command\Command', $display);
        $this->assertStringNotContainsString(AsEventListener::class, $display);
    }

    public function testSearchMatchesTagNames()
    {
        static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => 'config.yml', 'debug' => true]);

        $display = $this->runDebugAutoconfiguration(['search' => 'console.command.service_arguments'])->getDisplay();

        $this->assertStringContainsString('Symfony\Component\Console\Command\Command', $display);
        $this->assertStringNotContainsString('Symfony\Contracts\Service\ResetInterface', $display);
    }

    public function testSearchWithoutMatch()
    {
        static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => 'config.yml', 'debug' => true]);

        $tester = $this->runDebugAutoconfiguration(['search' => 'NoSuchType']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No autoconfiguration rules found matching "NoSuchType"', $tester->getDisplay());
    }

    public function testTagNamesAreShownUnescaped()
    {
        static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => 'escaped_percent.yml', 'debug' => true]);

        $tester = $this->runDebugAutoconfiguration([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString(PercentTagged::class."\n   tag app.%weird%.tag\n", $tester->getDisplay(true));
    }

    public function testComplete()
    {
        $kernel = static::bootKernel(['test_case' => 'ContainerDebug', 'root_config' => 'config.yml', 'debug' => true]);
        $command = (new Application($kernel))->addCommand(new DebugAutoconfigurationCommand());

        $suggestions = (new CommandCompletionTester($command))->complete(['']);

        $this->assertContains('Symfony\Contracts\Service\ResetInterface', $suggestions);
        $this->assertContains(AsEventListener::class, $suggestions);
    }

    private function runDebugAutoconfiguration(array $input): ApplicationTester
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'debug:autoconfiguration'] + $input, ['decorated' => false]);

        return $tester;
    }
}
