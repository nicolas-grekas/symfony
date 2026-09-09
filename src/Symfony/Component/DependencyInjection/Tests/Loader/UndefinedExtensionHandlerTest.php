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

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Loader\UndefinedExtensionHandler;

class UndefinedExtensionHandlerTest extends TestCase
{
    private const PACKAGES = ['notifier' => 'symfony/notifier', 'html_sanitizer' => 'symfony/html-sanitizer'];

    public function testADeclaredKeyNamesThePackageToInstall()
    {
        $this->assertSame(' Try running "composer require symfony/notifier".', UndefinedExtensionHandler::getPackageSuggestion('notifier', self::PACKAGES));
        $this->assertSame(' Try running "composer require symfony/html-sanitizer".', UndefinedExtensionHandler::getPackageSuggestion('html_sanitizer', self::PACKAGES));
    }

    public function testAnUndeclaredKeyIsNotGuessedAt()
    {
        // guessing "symfony/acme-foo" here would send the reader after a package that does not exist
        $this->assertSame('', UndefinedExtensionHandler::getPackageSuggestion('acme_foo', self::PACKAGES));
        $this->assertSame('', UndefinedExtensionHandler::getPackageSuggestion('notifier', []));
    }

    public function testTheSuggestionIsAppendedToTheErrorMessage()
    {
        $message = UndefinedExtensionHandler::getErrorMessage('notifier', '/app/config.yaml', 'notifier', ['framework'], self::PACKAGES);

        $this->assertSame('There is no extension able to load the configuration for "notifier" (in "/app/config.yaml"). Looked for namespace "notifier", found "framework". Try running "composer require symfony/notifier".', $message);
    }

    public function testTheMessageIsUnchangedWithoutADeclaredPackage()
    {
        $message = UndefinedExtensionHandler::getErrorMessage('notifier', '/app/config.yaml', 'notifier', ['framework']);

        $this->assertSame('There is no extension able to load the configuration for "notifier" (in "/app/config.yaml"). Looked for namespace "notifier", found "framework".', $message);
    }

    public function testAKnownBundleStillReportsItsBundle()
    {
        $message = UndefinedExtensionHandler::getErrorMessage('twig', null, 'twig', []);

        $this->assertStringStartsWith('Did you forget to install or enable the TwigBundle? ', $message);
        $this->assertStringEndsWith('found "none".', $message);
    }
}
