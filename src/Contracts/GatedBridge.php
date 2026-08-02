<?php

namespace Native\Mobile\Contracts;

/**
 * A bridge that can answer capability queries honestly.
 *
 * nativephp_can() assumes every method is available (true on device,
 * and the safe default for Jump / plain FakeBridge tests). A bridge
 * standing in for a different runtime — a browser today, a desktop
 * shell later — implements this so app code can feature-gate on what
 * that runtime actually provides, without core naming any concrete
 * bridge class.
 */
interface GatedBridge
{
    public function can(string $method): bool;
}
