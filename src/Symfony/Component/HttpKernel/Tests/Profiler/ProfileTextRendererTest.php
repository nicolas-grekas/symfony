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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\ConfigDataCollector;
use Symfony\Component\HttpKernel\DataCollector\ExceptionDataCollector;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\DataCollector\MemoryDataCollector;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\DataCollector\TimeDataCollector;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\ProfileTextRenderer;
use Symfony\Component\Stopwatch\Stopwatch;

class ProfileTextRendererTest extends TestCase
{
    public function testHeader()
    {
        $profile = $this->createProfile(Request::create('/'), new Response('', 204));

        $this->assertStringContainsString('# GET http://localhost/ -> 204', (new ProfileTextRenderer())->render($profile));
        $this->assertStringContainsString('Profile abc123 at ', (new ProfileTextRenderer())->render($profile));
    }

    public function testExceptionIsRenderedWithItsTrace()
    {
        $exception = new \RuntimeException('Could not load widget "42".');

        $collector = new ExceptionDataCollector();
        $collector->collect(new Request(), new Response('', 500), $exception);

        $profile = $this->createProfile(new Request(), new Response('', 500), [$collector]);
        $output = (new ProfileTextRenderer())->render($profile);

        $this->assertStringContainsString('## exception', $output);
        $this->assertStringContainsString('RuntimeException: Could not load widget "42".', $output);
        $this->assertMatchesRegularExpression('/#0\s+.*ProfileTextRendererTest\.php:\d+/', $output);
    }

    public function testTraceIsBounded()
    {
        $collector = new ExceptionDataCollector();
        $collector->collect(new Request(), new Response('', 500), $this->createDeepException(30));

        $profile = $this->createProfile(new Request(), new Response('', 500), [$collector]);
        $output = (new ProfileTextRenderer(maxTraceFrames: 5))->render($profile);

        $this->assertSame(5, preg_match_all('/^  #\d/m', $output));
        $this->assertStringContainsString('more frames', $output);
    }

    public function testLogsAreInterpolatedAndDebugEntriesAreOmitted()
    {
        $logger = $this->createStub(DebugLoggerInterface::class);
        $logger->method('getLogs')->willReturn([
            ['timestamp' => 1, 'timestamp_rfc3339' => '', 'message' => 'Matched route "{route}".', 'priority' => 200, 'priorityName' => 'info', 'context' => ['route' => 'homepage'], 'channel' => 'request'],
            ['timestamp' => 2, 'timestamp_rfc3339' => '', 'message' => 'Notified event "{event}".', 'priority' => 100, 'priorityName' => 'debug', 'context' => ['event' => 'kernel.request'], 'channel' => 'event'],
            ['timestamp' => 3, 'timestamp_rfc3339' => '', 'message' => 'Something went wrong', 'priority' => 400, 'priorityName' => 'warning', 'context' => [], 'channel' => 'app'],
        ]);
        $logger->method('countErrors')->willReturn(0);

        $collector = new LoggerDataCollector($logger);
        $collector->collect(new Request(), new Response());
        $collector->lateCollect();

        $profile = $this->createProfile(new Request(), new Response(), [$collector]);
        $output = (new ProfileTextRenderer())->render($profile);

        $this->assertStringContainsString('[info] Matched route "homepage".', $output);
        $this->assertStringContainsString('[warning] Something went wrong', $output);
        $this->assertStringNotContainsString('kernel.request', $output);
        $this->assertStringContainsString('(1 debug entries omitted)', $output);
    }

    public function testTimeReportsDurationsRatherThanStopwatchObjects()
    {
        $stopwatch = new Stopwatch(true);
        $stopwatch->openSection();
        $stopwatch->start('slow_thing');
        usleep(2000);
        $stopwatch->stop('slow_thing');
        $stopwatch->stopSection('main');

        $collector = new TimeDataCollector(null, $stopwatch);
        $request = new Request();
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true));
        $request->attributes->set('_stopwatch_token', 'main');
        $collector->collect($request, new Response());
        $collector->lateCollect();

        $output = (new ProfileTextRenderer())->render($this->createProfile($request, new Response(), [$collector]));

        $this->assertStringContainsString('## time', $output);
        $this->assertMatchesRegularExpression('/\d+\.\d ms\s+slow_thing/', $output);
        $this->assertStringNotContainsString('StopwatchEvent', $output);
    }

    public function testRequestPanel()
    {
        $collector = new RequestDataCollector();
        $request = Request::create('/widgets?page=3', 'GET');
        $request->attributes->set('_route', 'widget_list');
        $collector->collect($request, new Response('', 200));
        $collector->lateCollect();

        $output = (new ProfileTextRenderer())->render($this->createProfile($request, new Response('', 200), [$collector]));

        $this->assertStringContainsString('- route: widget_list', $output);
        $this->assertStringContainsString('- query: page=3', $output);
    }

    public function testCollectorsThatAreNotUnderstoodAreSkippedRatherThanDumped()
    {
        $collector = new ConfigDataCollector();
        $collector->collect(new Request(), new Response());

        $output = (new ProfileTextRenderer())->render($this->createProfile(new Request(), new Response(), [$collector]));

        $this->assertStringNotContainsString('config', $output);
        $this->assertStringNotContainsString('symfony_version', $output);
    }

    public function testMemoryIsReportedInTheHeaderOnly()
    {
        $collector = new MemoryDataCollector();
        $collector->collect(new Request(), new Response());

        $output = (new ProfileTextRenderer())->render($this->createProfile(new Request(), new Response(), [$collector]));

        $this->assertMatchesRegularExpression('/, \d+(\.\d)? MiB\)/', $output);
        $this->assertStringNotContainsString('## memory', $output);
    }

    private function createProfile(Request $request, Response $response, array $collectors = []): Profile
    {
        $profile = new Profile('abc123');
        $profile->setMethod($request->getMethod());
        $profile->setUrl($request->getUri());
        $profile->setStatusCode($response->getStatusCode());
        $profile->setTime(time());
        $profile->setCollectors($collectors);

        return $profile;
    }

    private function createDeepException(int $depth): \Throwable
    {
        if ($depth <= 0) {
            return new \LogicException('deep');
        }

        try {
            throw $this->createDeepException($depth - 1);
        } catch (\Throwable $e) {
            return $e;
        }
    }
}
