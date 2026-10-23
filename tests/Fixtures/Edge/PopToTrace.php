<?php

namespace Tests\Fixtures\Edge;

/**
 * Ordered lifecycle trace shared by the popTo fixtures. Every hook
 * appends as it runs, so tests can assert the ORDER the router drove
 * the screens in — e.g. a popTo no-op must resume nothing until the
 * screen itself finishes, and a single back() must not unwind past the
 * screen directly below.
 */
final class PopToTrace
{
    /** @var list<string> */
    public static array $sequence = [];

    public static function record(string $event): void
    {
        self::$sequence[] = $event;
    }

    /** @var list<string> */
    public static array $events = [];

    public static function recordEvent(object $event): void
    {
        self::$events[] = class_basename($event).'|'.$event->component.'|'.($event->uri ?? '');
    }

    public static function reset(): void
    {
        self::$sequence = [];
        self::$events = [];
    }

    public static function resetAll(): void
    {
        self::reset();

        foreach ([
            LogFeedScreen::class,
            ThreadScreen::class,
            PopToTargetScreen::class,
            PlainBackScreen::class,
            PopToSelfScreen::class,
            FallbackProbeScreen::class,
            PopToCaptureScreen::class,
            PopToFlowFeedScreen::class,
            PopToFlowThreadScreen::class,
            PopToFlowReplyScreen::class,
        ] as $class) {
            $class::resetCounters();
        }
    }
}
