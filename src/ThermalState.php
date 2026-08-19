<?php

namespace Native\Mobile;

/**
 * Normalized device thermal state.
 *
 * iOS maps 1:1 from `ProcessInfo.ThermalState` (`nominal` / `fair` /
 * `serious` / `critical`). Android's seven `PowerManager` statuses collapse
 * onto the closest name: `NONE` → Normal, `LIGHT` → Warm, `MODERATE` and
 * `SEVERE` → Hot, `CRITICAL` / `EMERGENCY` / `SHUTDOWN` → Critical.
 *
 * Android 8–9 (API 26–28) has no thermal API and always reports Normal.
 */
enum ThermalState: string
{
    case Normal = 'normal';
    case Warm = 'warm';
    case Hot = 'hot';
    case Critical = 'critical';

    public function severity(): int
    {
        return match ($this) {
            self::Normal => 0,
            self::Warm => 1,
            self::Hot => 2,
            self::Critical => 3,
        };
    }

    /** Warm or worse — anything above a cool device. */
    public function isWarm(): bool
    {
        return $this->severity() >= self::Warm->severity();
    }

    /** Hot or Critical — time to start throttling heavy work. */
    public function isHot(): bool
    {
        return $this->severity() >= self::Hot->severity();
    }

    public function isCritical(): bool
    {
        return $this === self::Critical;
    }

    /** This bucket is hotter than `$other` (e.g. Hot vs Warm). */
    public function isWarmerThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }

    /** This bucket is cooler than `$other` (e.g. Normal vs Critical). */
    public function isCoolerThan(self $other): bool
    {
        return $this->severity() < $other->severity();
    }
}
