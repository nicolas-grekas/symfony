<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Profiler;

use Symfony\Component\HttpKernel\DataCollector\ExceptionDataCollector;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\DataCollector\TimeDataCollector;

/**
 * Writes a text rendering of the profiles that are worth looking at.
 *
 * The point is that nobody has to know this exists: the file is simply there,
 * next to the profiler storage, for whoever opens the project next. Profiles
 * for requests that went fine are not rendered, so the cost stays where the
 * value is.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class ProfileTextWriter implements ProfilerStorageInterface
{
    public function __construct(
        private ProfilerStorageInterface $storage,
        private string $directory,
        private ProfileTextRenderer $renderer = new ProfileTextRenderer(),
        private float $slowRequestThreshold = 1000.0,
    ) {
    }

    public function write(Profile $profile): bool
    {
        $written = $this->storage->write($profile);

        if ($written && $this->isWorthRendering($profile)) {
            $this->dump($profile);
        }

        return $written;
    }

    public function find(?string $ip, ?string $url, ?int $limit, ?string $method, ?int $start = null, ?int $end = null, ?string $statusCode = null, ?\Closure $filter = null): array
    {
        return $this->storage->find($ip, $url, $limit, $method, $start, $end, $statusCode, $filter);
    }

    public function read(string $token): ?Profile
    {
        return $this->storage->read($token);
    }

    public function purge(): void
    {
        $this->storage->purge();

        foreach (glob($this->directory.'/*.md') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * A request is worth rendering when someone is likely to want to read about it.
     */
    private function isWorthRendering(Profile $profile): bool
    {
        if (($profile->getStatusCode() ?? 200) >= 400 || $profile->hasErrors()) {
            return true;
        }

        foreach ($profile->getCollectors() as $collector) {
            if ($collector instanceof ExceptionDataCollector && $collector->hasException()) {
                return true;
            }

            if ($collector instanceof LoggerDataCollector
                && ($collector->countErrors() || $collector->countWarnings() || $collector->countDeprecations())
            ) {
                return true;
            }

            if ($collector instanceof TimeDataCollector && $collector->getDuration() > $this->slowRequestThreshold) {
                return true;
            }
        }

        return false;
    }

    private function dump(Profile $profile): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            return;
        }

        $text = $this->renderer->render($profile);

        // the token file keeps the history, "last" is the one to open without knowing anything
        @file_put_contents($this->directory.'/'.$profile->getToken().'.md', $text);
        @file_put_contents($this->directory.'/last.md', $text);
    }
}
