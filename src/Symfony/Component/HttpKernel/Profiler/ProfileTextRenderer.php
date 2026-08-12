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

use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpKernel\DataCollector\ExceptionDataCollector;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\DataCollector\MemoryDataCollector;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\DataCollector\TimeDataCollector;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * Renders a profile as plain text, for people and tools that don't run a browser.
 *
 * Only the collectors listed below are rendered; anything else, including collectors
 * from third-party bundles, is skipped rather than dumped. The output is a summary
 * meant to be read, not parsed: its shape carries no backward compatibility promise.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class ProfileTextRenderer
{
    public function __construct(
        private int $maxLogs = 25,
        private int $maxTraceFrames = 15,
        private int $maxTimeEvents = 8,
    ) {
    }

    public function render(Profile $profile): string
    {
        $output = $this->renderHeader($profile);

        foreach ($profile->getCollectors() as $collector) {
            try {
                $output .= match (true) {
                    $collector instanceof ExceptionDataCollector => $this->renderException($collector),
                    $collector instanceof RequestDataCollector => $this->renderRequest($collector),
                    $collector instanceof LoggerDataCollector => $this->renderLogs($collector),
                    $collector instanceof TimeDataCollector => $this->renderTime($collector),
                    default => '',
                };
            } catch (\Throwable $e) {
                // a rendering summary is never worth breaking the response over
                $output .= \sprintf("\n## %s\n\n  (could not be rendered: %s)\n", $collector->getName(), $e->getMessage());
            }
        }

        return $output;
    }

    private function renderHeader(Profile $profile): string
    {
        $duration = $memory = null;

        foreach ($profile->getCollectors() as $collector) {
            if ($collector instanceof TimeDataCollector) {
                $duration = round($collector->getDuration()).' ms';
            } elseif ($collector instanceof MemoryDataCollector) {
                $memory = round($collector->getMemory() / 1024 / 1024, 1).' MiB';
            }
        }

        return \sprintf(
            "# %s %s -> %s (%s, %s)\n\nProfile %s at %s\n",
            $profile->getMethod(),
            $profile->getUrl(),
            $profile->getStatusCode() ?? '?',
            $duration ?? 'n/a',
            $memory ?? 'n/a',
            $profile->getToken(),
            date('c', $profile->getTime()),
        );
    }

    private function renderException(ExceptionDataCollector $collector): string
    {
        if (!$collector->hasException()) {
            return '';
        }

        $exception = $collector->getException();
        $output = "\n## exception\n\n".$exception->getClass().': '.$exception->getMessage()."\n";

        $trace = self::toArray($collector->getTrace());

        foreach (\array_slice($trace, 0, $this->maxTraceFrames) as $i => $frame) {
            $function = '';
            if ('' !== ($frame['class'] ?? '')) {
                $function = '  '.$frame['class'].($frame['type'] ?? '::').($frame['function'] ?? '').'()';
            } elseif ('' !== ($frame['function'] ?? '')) {
                $function = '  '.$frame['function'].'()';
            }

            $output .= \sprintf("  #%-2d %s:%s%s\n", $i, $frame['file'] ?? '?', $frame['line'] ?? '?', $function);
        }

        if (\count($trace) > $this->maxTraceFrames) {
            $output .= \sprintf("  ... %d more frames\n", \count($trace) - $this->maxTraceFrames);
        }

        return $output;
    }

    private function renderRequest(RequestDataCollector $collector): string
    {
        $controller = $collector->getController();
        if (!\is_string($controller)) {
            $controller = self::toArray($controller);
            $controller = ($controller['class'] ?? '?').'::'.($controller['method'] ?? '?');
        }

        $output = "\n## request\n\n"
            .'- route: '.($collector->getRoute() ?: 'n/a')."\n"
            .'- controller: '.$controller."\n"
            .'- content type: '.$collector->getContentType()."\n";

        $bags = [
            'query' => $collector->getRequestQuery(),
            'body' => $collector->getRequestRequest(),
            'route params' => $collector->getRouteParams(),
        ];

        foreach ($bags as $label => $bag) {
            if ($pairs = self::flatten(self::toArray($bag))) {
                $output .= '- '.$label.': '.implode(', ', $pairs)."\n";
            }
        }

        return $output;
    }

    private function renderLogs(LoggerDataCollector $collector): string
    {
        $logs = self::toArray($collector->getLogs());

        if (!$logs) {
            return '';
        }

        // debug entries are the framework tracing itself, which crowds out the application's own logs
        $kept = [];
        $skipped = 0;
        foreach ($logs as $log) {
            if ('debug' === ($log['priorityName'] ?? '')) {
                ++$skipped;
            } elseif (\count($kept) < $this->maxLogs) {
                $kept[] = $log;
            }
        }

        $remaining = \count($logs) - $skipped - \count($kept);

        $output = \sprintf(
            "\n## logs (%d errors, %d warnings, %d deprecations)\n\n",
            $collector->countErrors(),
            $collector->countWarnings(),
            $collector->countDeprecations(),
        );

        foreach ($kept as $log) {
            $output .= \sprintf("  [%s] %s\n", $log['priorityName'] ?? '?', self::oneLine($this->interpolate($log)));
        }

        if ($remaining > 0) {
            $output .= \sprintf("  ... %d more\n", $remaining);
        }

        if ($skipped > 0) {
            $output .= \sprintf("  (%d debug entries omitted)\n", $skipped);
        }

        return $output;
    }

    private function renderTime(TimeDataCollector $collector): string
    {
        $events = $collector->getEvents();
        $events = $events instanceof Data ? $events->getValue() : $events;
        unset($events['__section__']);

        if (!$events) {
            return '';
        }

        uasort($events, static fn ($a, $b) => $b->getDuration() <=> $a->getDuration());

        $output = "\n## time\n\n";

        foreach (\array_slice($events, 0, $this->maxTimeEvents, true) as $name => $event) {
            $output .= \sprintf("  %8.1f ms  %s\n", $event->getDuration(), $name);
        }

        return $output;
    }

    /**
     * Log messages are stored as templates, with the values kept in the context.
     */
    private function interpolate(array $log): string
    {
        $message = (string) ($log['message'] ?? '');
        $replacements = [];

        foreach (self::toArray($log['context'] ?? []) as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return $replacements ? strtr($message, $replacements) : $message;
    }

    /**
     * @return list<string>
     */
    private static function flatten(array $values): array
    {
        $pairs = [];

        foreach ($values as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }

            $pairs[] = $key.'='.match (true) {
                \is_scalar($value) => (string) $value,
                $value instanceof \Stringable => (string) $value,
                default => get_debug_type($value),
            };
        }

        return \array_slice($pairs, 0, 20);
    }

    private static function toArray(mixed $value): array
    {
        if ($value instanceof ParameterBag) {
            $value = $value->all();
        }

        if ($value instanceof Data) {
            $value = $value->getValue(true);
        }

        return \is_array($value) ? $value : [];
    }

    private static function oneLine(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message));

        return \strlen($message) > 200 ? substr($message, 0, 200).'...' : $message;
    }
}
