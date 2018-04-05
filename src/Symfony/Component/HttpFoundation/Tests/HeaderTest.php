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

class HeaderTest extends TestCase
{
    private static $server;

    public static function setUpBeforeClass()
    {
        $spec = array(
            1 => array('file', '/dev/null', 'w'),
            2 => array('file', '/dev/null', 'w'),
        );
        if (!self::$server = @proc_open('exec php -S localhost:8054', $spec, $pipes, __DIR__.'/Fixtures')) {
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

    public function testHeader()
    {
        $result = file_get_contents('http://localhost:8054/send_headers_cookie_samesite.php');
        $this->assertStringEqualsFile(__DIR__ . '/Fixtures/send_headers_cookie_samesite.expected', $result);
    }

}
