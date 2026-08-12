<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\ExceptionDataCollector;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\ProfileTextWriter;

class ProfileTextWriterTest extends TestCase
{
    private string $tmp;
    private string $artifacts;
    private ProfileTextWriter $writer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/sf_profile_text_'.bin2hex(random_bytes(4));
        $this->artifacts = $this->tmp.'/profiles';
        $this->writer = new ProfileTextWriter(new FileProfilerStorage('file:'.$this->tmp.'/storage'), $this->artifacts);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    public function testASuccessfulRequestIsNotRendered()
    {
        $this->writer->write($this->createProfile('quiet', 200));

        $this->assertFileDoesNotExist($this->artifacts.'/last.md');
    }

    public function testAFailingRequestIsRendered()
    {
        $this->writer->write($this->createProfile('failed', 500));

        $this->assertFileExists($this->artifacts.'/last.md');
        $this->assertFileExists($this->artifacts.'/failed.md');
        $this->assertStringContainsString('-> 500', file_get_contents($this->artifacts.'/last.md'));
    }

    public function testARequestThatRaisedAnExceptionIsRenderedEvenWithA200()
    {
        $collector = new ExceptionDataCollector();
        $collector->collect(new Request(), new Response(), new \RuntimeException('caught, but noteworthy'));

        $this->writer->write($this->createProfile('caught', 200, [$collector]));

        $this->assertStringContainsString('caught, but noteworthy', file_get_contents($this->artifacts.'/last.md'));
    }

    public function testLastPointsAtTheMostRecentlyRenderedProfile()
    {
        $this->writer->write($this->createProfile('first', 500));
        $this->writer->write($this->createProfile('second', 503));

        $this->assertStringContainsString('Profile second', file_get_contents($this->artifacts.'/last.md'));
    }

    public function testTheUnderlyingStorageKeepsWorking()
    {
        $profile = $this->createProfile('stored', 500);

        $this->assertTrue($this->writer->write($profile));
        $this->assertSame('stored', $this->writer->read('stored')?->getToken());
    }

    public function testPurgeAlsoRemovesTheRenderings()
    {
        $this->writer->write($this->createProfile('failed', 500));
        $this->assertFileExists($this->artifacts.'/last.md');

        $this->writer->purge();

        $this->assertFileDoesNotExist($this->artifacts.'/last.md');
        $this->assertFileDoesNotExist($this->artifacts.'/failed.md');
    }

    private function createProfile(string $token, int $statusCode, array $collectors = []): Profile
    {
        $profile = new Profile($token);
        $profile->setMethod('GET');
        $profile->setUrl('http://localhost/');
        $profile->setStatusCode($statusCode);
        $profile->setTime(time());
        $profile->setIp('127.0.0.1');
        $profile->setCollectors($collectors);

        return $profile;
    }
}
