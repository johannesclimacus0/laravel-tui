<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Scheduler;

use Carbon\CarbonImmutable;
use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionObject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Throwable;

class ScheduleRepository
{
    public function __construct(private Schedule $schedule) {}

    /** @return list<ScheduledTask> */
    public function tasks(): array
    {
        $tasks = [];
        foreach ($this->schedule->events() as $index => $event) {
            $command = $event instanceof CallbackEvent
                ? $event->getSummaryForDisplay()
                : $event->normalizeCommand($event->command ?? '');
            $label = $event->description ?: $command;
            $timezone = $event->timezone ?: config('app.timezone', 'UTC');
            $timezone = $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone;
            $frequency = $this->frequency($event);
            $environment = app()->environment();
            $environmentNotice = $event->runsInEnvironment($environment)
                ? '' : 'Only: '.implode(', ', $event->environments);
            $next = 'Unavailable';
            $error = null;

            try {
                $now = CarbonImmutable::now($timezone);
                $cron = new CronExpression($event->expression);
                $date = CarbonImmutable::instance($cron->getNextRunDate($now, timeZone: $timezone));

                if ($event->isRepeatable() && $cron->isDue($now, $timezone)) {
                    $date = $now->endOfSecond()->ceilSeconds($event->repeatSeconds);
                }
                $next = $date->setTimezone($timezone)->format('Y-m-d H:i:s P');
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
            $details = [
                $label,
                '',
                'Command: '.$command,
                'Type: '.($event instanceof CallbackEvent ? 'Callback / queued job' : 'Command'),
                'Frequency: '.$frequency,
                'Cron: '.$event->expression,
                $this->source($event),
                'Timezone: '.$timezone,
                'Next scheduled time: '.$next,
                'Repeat: '.($event->repeatSeconds === null ? 'Once per matching minute' : 'Every '.$event->repeatSeconds.' seconds'),
                'Current environment: '.$environment,
                'Environment eligibility: '.($environmentNotice === '' ? 'Allowed' : 'Excluded'),
                'Environments: '.($event->environments === [] ? 'All' : implode(', ', $event->environments)),
                'Without overlapping: '.($event->withoutOverlapping ? 'Yes' : 'No'),
                'On one server: '.($event->onOneServer ? 'Yes' : 'No'),
                'Background: '.($event->runInBackground ? 'Yes' : 'No'),
                'During maintenance: '.($event->evenInMaintenanceMode ? 'Yes' : 'No'),
            ];

            if ($error !== null) {
                $details[] = 'Schedule error: '.$error;
            }
            $tasks[] = new ScheduledTask(
                (string) $index,
                $this->plain($label),
                $this->plain($event->expression),
                $this->plain($next),
                array_map($this->plain(...), $details),
                $this->plain($frequency),
                $this->plain($environmentNotice),
            );
        }

        return $tasks;
    }

    private function frequency(Event $event): string
    {
        $expression = trim($event->expression);

        if (! CronExpression::isValidExpression($expression)) {
            return 'Invalid schedule';
        }
        $parts = preg_split('/\s+/', $expression);

        if ($parts === false || count($parts) !== 5) {
            return 'Custom schedule';
        }
        [$minute, $hour, $day, $month, $weekday] = $parts;
        $frequency = 'Custom schedule';

        if ($expression === '* * * * *') {
            $frequency = 'Every minute';
        } elseif ($day === '*' && $month === '*' && $weekday === '*') {
            if ($hour === '*' && preg_match('/^\*\/([1-9]\d*)$/', $minute, $match) && 60 % (int) $match[1] === 0) {
                $frequency = (int) $match[1] === 1 ? 'Every minute' : 'Every '.$match[1].' minutes';
            } elseif (ctype_digit($minute) && $hour === '*') {
                $frequency = 'Hourly at :'.str_pad($minute, 2, '0', STR_PAD_LEFT);
            } elseif (ctype_digit($minute) && ctype_digit($hour)) {
                $frequency = 'Daily at '.sprintf('%02d:%02d', (int) $hour, (int) $minute);
            }
        } elseif ($day === '*' && $month === '*' && ctype_digit($minute) && ctype_digit($hour)) {
            $days = match ($weekday) {
                '1-5' => 'Weekdays',
                '0,6', '6,0', '6,7', '7,6' => 'Weekends',
                '0', '7' => 'Sundays',
                '1' => 'Mondays',
                '2' => 'Tuesdays',
                '3' => 'Wednesdays',
                '4' => 'Thursdays',
                '5' => 'Fridays',
                '6' => 'Saturdays',
                default => null,
            };

            if ($days !== null) {
                $frequency = $days.' at '.sprintf('%02d:%02d', (int) $hour, (int) $minute);
            }
        }

        if ($event->repeatSeconds !== null) {
            return $expression === '* * * * *'
                ? 'Every '.$event->repeatSeconds.' seconds'
                : $frequency.'; every '.$event->repeatSeconds.' seconds within matching minutes';
        }

        return $frequency;
    }

    private function source(Event $event): string
    {
        if (! $event instanceof CallbackEvent) {
            return 'Schedule source: Not retained by Laravel';
        }

        try {
            $callback = (new ReflectionObject($event))->getProperty('callback')->getValue($event);
            $reflection = match (true) {
                $callback instanceof Closure => new ReflectionFunction($callback),
                is_array($callback) && count($callback) === 2 => new ReflectionMethod($callback[0], $callback[1]),
                is_string($callback) && str_contains($callback, '::') => new ReflectionMethod(...explode('::', $callback, 2)),
                is_string($callback) => new ReflectionFunction($callback),
                is_object($callback) => new ReflectionMethod($callback, '__invoke'),
                default => null,
            };

            if ($reflection === null) {
                return 'Callback source: Unavailable';
            }
            $file = $reflection->getFileName();
            $line = $reflection->getStartLine();

            if ($file !== false && $line !== false) {
                return 'Callback source: '.$file.':'.$line;
            }
        } catch (Throwable) {
        }

        return 'Callback source: Unavailable';
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($text)));
    }
}
