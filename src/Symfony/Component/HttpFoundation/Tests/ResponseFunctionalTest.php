<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpFoundation\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @requires PHP 7.0
 */
class ResponseFunctionalTest extends TestCase
{
    private static $server;

    public static function setUpBeforeClass()
    {
        $spec = array(
            1 => array('file', '/dev/null', 'w'),
            2 => array('file', '/dev/null', 'w'),
        );
        if (!self::$server = @proc_open('exec php -S localhost:8054', $spec, $pipes, __DIR__.'/Fixtures/response-functional')) {
            self::markTestSkipped('PHP server unable to start.');
        }
        sleep(1);
    }

    public static function tearDownAfterClass()
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /**
     * @dataProvider provideCookie
     */
    public function testCookieSamesite($fixture)
    {
        $result = file_get_contents(sprintf('http://localhost:8054/%s.php', $fixture));
        $this->assertStringEqualsFile(__DIR__.sprintf('/Fixtures/response-functional/%s.expected', $fixture), $result);
    }

    public function testCookieShouldThrowExceptionForInvalidName()
    {
        $result = file_get_contents('http://localhost:8054/response-functional/invalid_cookie_name.php');
        $this->assertStringEqualsFile(__DIR__.'/Fixtures/response-functional/invalid_cookie_name.expected', $result);
    }

    public function testcookieShouldUrlEncode()
    {
        $url = 'https://blackfire.io/?*():@&+$/%#[]';
        setcookie($url,$url);
        //WIP
        $result = file_get_contents('http://localhost:8054/response-functional/cookie_urlencode.php');
        $this->assertStringEqualsFile(__DIR__.'/Fixtures/response-functional/cookie_urlencode.expected', $result);
    }

    public function provideCookie()
    {
        foreach (glob(__DIR__.'/Fixtures/response-functional/cookie_*.php') as $file) {
            yield array(pathinfo($file, PATHINFO_FILENAME));
        }
    }
}
