<?php

namespace Native\Mobile\Edge;

/**
 * Published 2025-2026 performance reference data for cross-framework
 * comparison. Sourced from the SynergyBoat "Flutter vs React Native vs
 * Native (Swift / Kotlin)" benchmark series — same test methodology so
 * our numbers and theirs are apples-to-apples.
 *
 * Each entry is a 100-row scrolling list scenario unless noted; metrics
 * are averaged across a 5-second capture window.
 *
 * Surfaced inline in `BenchmarkComponent` so the user can eyeball our
 * numbers against the rest of the ecosystem without leaving the screen.
 */
class BenchmarkReferenceData
{
    /**
     * Reference frames-of-interest by platform and framework. Keys map
     * 1:1 with `BenchmarkComponent` metric names so a results card can
     * show "ours vs theirs" without bespoke wiring per scenario.
     *
     * Numbers are intentionally rounded to one decimal — false precision
     * would imply tighter measurement than these published runs warrant.
     */
    public const LIST_SCROLL = [
        'ios_60hz' => [
            'native_swift'    => ['avg_ms' => 17.2, 'p95_ms' => 16.7, 'fps' => 58.5, 'dropped_pct' => 1.6, 'jank_pct' => 1.4, 'ttff_ms' => 41.4,  'memory_mb' => 9.7],
            'flutter'         => ['avg_ms' => 1.7,  'p95_ms' => 2.5,  'fps' => 59.3, 'dropped_pct' => 0.0, 'jank_pct' => 0.0, 'ttff_ms' => 16.7,  'memory_mb' => 25.3],
            'react_native'    => ['avg_ms' => 16.7, 'p95_ms' => 16.8, 'fps' => 57.5, 'dropped_pct' => 15.5,'jank_pct' => 1.8, 'ttff_ms' => 33.0,  'memory_mb' => 45.1],
        ],
        'android_120hz' => [
            'native_kotlin'   => ['avg_ms' => 8.3,  'p95_ms' => 8.3,  'fps' => 119.8,'dropped_pct' => 0.0, 'jank_pct' => 0.0, 'ttff_ms' => 16.0,  'memory_mb' => 6.3],
            'flutter'         => ['avg_ms' => 4.0,  'p95_ms' => 5.1,  'fps' => 117.8,'dropped_pct' => 0.0, 'jank_pct' => 0.0, 'ttff_ms' => 10.3,  'memory_mb' => 14.0],
            'react_native'    => ['avg_ms' => 8.3,  'p95_ms' => 8.3,  'fps' => 115.0,'dropped_pct' => 0.0, 'jank_pct' => 0.0, 'ttff_ms' => 15.3,  'memory_mb' => 33.0],
        ],
    ];

    /**
     * Cold start published numbers (process launch -> first frame), in
     * milliseconds. App-specific so take with a grain of salt, but
     * useful as an order-of-magnitude check.
     */
    public const COLD_START_MS = [
        'ios'     => ['native_swift'  => 800, 'flutter' => 1100, 'react_native' => 2400],
        'android' => ['native_kotlin' => 700, 'flutter' => 950,  'react_native' => 2100],
    ];

    /**
     * Approximate bundle / install sizes for a 100-row list app shell.
     * MB. From the same article. Build-time, not measurable at runtime.
     */
    public const BUNDLE_SIZE_MB = [
        'native_swift'   => 1.2,
        'native_kotlin'  => 2.8,
        'flutter'        => 17.4,
        'react_native'   => 11.6,
    ];

    /**
     * Pick the right list-scroll reference set for the platform the
     * suite is currently running on. Returns one of the LIST_SCROLL
     * entries with platform-specific framework rows.
     */
    public static function listScrollFor(string $platform): array
    {
        return match (strtolower($platform)) {
            'ios', 'darwin' => self::LIST_SCROLL['ios_60hz'],
            default          => self::LIST_SCROLL['android_120hz'],
        };
    }
}
