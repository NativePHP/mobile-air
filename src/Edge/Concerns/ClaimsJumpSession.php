<?php

namespace Native\Mobile\Edge\Concerns;

use Native\Mobile\JumpBridge;

/**
 * Making sure exactly one runloop drives the device under `native:jump`.
 *
 * Jump is the hybrid dev mode: PHP runs on the developer's machine behind
 * `artisan serve` while the UI renders on a real device over a socket. The
 * runloop has to be immortal there (see `set_time_limit(0)` in `runLoop()`),
 * and that creates a hazard unique to Jump — when a WebView reload or a
 * re-scan opens a new `GET /`, the previous runloop's request is abandoned by
 * the client yet keeps running forever, publishing over the SHARED device
 * bridge and ping-ponging WaitEvents with the new session.
 *
 * The fix is a last-writer-wins marker file: each runloop stamps its own
 * token, and any older loop notices it no longer owns the marker and bails
 * out. None of this exists on device, and none of it exists on a platform
 * without a dev bridge — which is why it is a mobile trait rather than part
 * of core's loop.
 */
trait ClaimsJumpSession
{
    /**
     * Claim this runloop as the current native session, when there is a Jump
     * session to claim; null otherwise (device, tests, plain web).
     *
     * Gated on JUMP_BRIDGE_PORT (set only by `native:jump`). NOT on
     * `function_exists('nativephp_call')` — in Jump mode the PHP fallback
     * DEFINES that function, so it exists on both device and dev server and
     * would gate this off everywhere.
     */
    protected function claimJumpSessionIfUnderJump(): ?string
    {
        if (getenv('JUMP_BRIDGE_PORT') === false) {
            return null;
        }

        return $this->claimJumpSession();
    }

    /**
     * True when a newer Jump session has taken the marker, meaning this
     * runloop is an orphan whose WebView is gone.
     *
     * The device-side bridge wakes the loser from `wait_event()` via
     * supersession; it must then bail WITHOUT touching the bridge, or its
     * teardown (unmount → element_shutdown) would wipe the live session's
     * tree. `mute()` turns every subsequent bridge call into a no-op; the
     * null navigation intent then unwinds the stack quietly.
     */
    protected function supersededByNewerJumpSession(?string $token): bool
    {
        if ($token === null || $this->isCurrentJumpSession($token)) {
            return false;
        }

        if (class_exists(JumpBridge::class)) {
            JumpBridge::instance()->mute();
        }

        return true;
    }

    /**
     * Shared marker naming the most recently started Jump native session.
     * One dev server drives one device, so a single file is sufficient.
     */
    protected function jumpSessionFile(): string
    {
        return sys_get_temp_dir().'/nativephp_jump_session';
    }

    /**
     * Stamp this runloop as the current Jump native session and return its
     * unique token. The last writer wins, so the newest `GET /` supersedes
     * every older runloop (which then exits via isCurrentJumpSession()).
     */
    protected function claimJumpSession(): string
    {
        $token = getmypid().'-'.hrtime(true).'-'.mt_rand(1000, 9999);
        @file_put_contents($this->jumpSessionFile(), $token, LOCK_EX);

        return $token;
    }

    /**
     * True while this runloop still owns the session marker. Fails open: a
     * missing/unreadable marker never kills the loop.
     */
    protected function isCurrentJumpSession(string $token): bool
    {
        $current = @file_get_contents($this->jumpSessionFile());

        return $current === false || $current === '' || $current === $token;
    }
}
