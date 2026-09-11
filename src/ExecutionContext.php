<?php

namespace Native\Mobile;

/**
 * Where the app is running right now, and why the process started.
 *
 * iOS cold-launches an app straight into the background to run scheduled work
 * — a BGTaskScheduler task, a silent push, background fetch — routinely while
 * the device is locked. The process is fully booted, Laravel is up and your
 * scheduled command runs, but there is no user, no screen, and Data Protection
 * may have sealed the keychain and protected files.
 *
 * NativePHP holds the interactive boot back for exactly that case (the start
 * route is not dispatched, no screen is mounted, no WebView is created), and
 * this is how your app asks the same questions:
 *
 *     if (ExecutionContext::isHeadless()) {
 *         // Woken for background work — do the job, don't touch the UI.
 *         return;
 *     }
 *
 *     if (! ExecutionContext::isProtectedDataAvailable()) {
 *         // Device locked: keychain items and protected files are unreadable.
 *         return;
 *     }
 *
 * Android's runtime is started by its Activity, so it always reports a
 * foreground launch; `isProtectedDataAvailable()` maps to the direct-boot
 * user-unlocked state there. Off the device (desktop `php artisan`, tests, web
 * preview) every read reports an ordinary interactive process.
 *
 * @see \Native\Mobile\Facades\ExecutionContext
 */
class ExecutionContext
{
    /**
     * What to report when there is no native bridge to ask — an ordinary
     * interactive process, the shape every existing code path assumes. Also
     * the fallback for a native shell built before `System.GetExecutionContext`
     * existed, so upgrading the PHP package alone never flips an app into
     * thinking it is headless.
     */
    private const DEFAULTS = [
        'launch' => 'foreground',
        'state' => 'active',
        'foreground' => true,
        'active' => true,
        'has_become_active' => true,
        'headless' => false,
        'protected_data_available' => true,
        'interactive_boot_started' => true,
    ];

    /**
     * The full context as reported by the platform.
     *
     * Deliberately un-cached. A native screen's PHP request blocks for the
     * whole life of that screen, so a value captured once would still claim
     * "active" long after the user backgrounded the app. The bridge call is an
     * in-process hop into Swift/Kotlin, not IPC.
     *
     * @return array{
     *     launch: string,
     *     state: string,
     *     foreground: bool,
     *     active: bool,
     *     has_become_active: bool,
     *     headless: bool,
     *     protected_data_available: bool,
     *     interactive_boot_started: bool,
     * }
     */
    public function all(): array
    {
        if (! function_exists('nativephp_call')) {
            return self::DEFAULTS;
        }

        $decoded = json_decode(nativephp_call('System.GetExecutionContext', '{}') ?: '{}', true);

        if (! is_array($decoded) || $decoded === []) {
            return self::DEFAULTS;
        }

        // Union fills anything the native side didn't report, so a newer PHP
        // package against an older native shell degrades key by key.
        return $decoded + self::DEFAULTS;
    }

    /**
     * 'active'     — frontmost and receiving events
     * 'inactive'   — on screen but not receiving events (app switcher,
     *                incoming call banner, a system permission alert)
     * 'background' — not on screen
     */
    public function state(): string
    {
        return (string) $this->all()['state'];
    }

    /** Why the process started: 'foreground' (a user opened it) or 'background'. */
    public function launch(): string
    {
        return (string) $this->all()['launch'];
    }

    /** Frontmost and receiving events. */
    public function isActive(): bool
    {
        return (bool) $this->all()['active'];
    }

    /** On screen — covers both 'active' and the transient 'inactive'. */
    public function isForeground(): bool
    {
        return (bool) $this->all()['foreground'];
    }

    /** Not on screen. */
    public function isBackground(): bool
    {
        return ! $this->isForeground();
    }

    /** The system cold-launched this process for background work. */
    public function launchedInBackground(): bool
    {
        return $this->launch() === 'background';
    }

    /** The app has been on screen at least once in this process. */
    public function hasBecomeActive(): bool
    {
        return (bool) $this->all()['has_become_active'];
    }

    /**
     * A background launch that has never been on screen — the state scheduled
     * Artisan commands and queued jobs run in when iOS wakes the app for them.
     * Nothing here should touch the UI, navigate, or start polling.
     */
    public function isHeadless(): bool
    {
        return (bool) $this->all()['headless'];
    }

    /**
     * Whether protected files and keychain items can be read. False on iOS
     * while the device is locked with Data Protection engaged; on Android,
     * false before the user's first unlock after boot.
     */
    public function isProtectedDataAvailable(): bool
    {
        return (bool) $this->all()['protected_data_available'];
    }

    /**
     * Whether the interactive boot has run — the point at which
     * `NATIVEPHP_START_URL` was dispatched and the first screen was created.
     * False throughout a headless background launch.
     */
    public function interactiveBootStarted(): bool
    {
        return (bool) $this->all()['interactive_boot_started'];
    }
}
