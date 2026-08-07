<?php

namespace Native\Mobile\Contracts;

/**
 * A bridge that can answer capability queries honestly.
 *
 * nativephp_can() assumes every method is available (true on device,
 * and the safe default for Jump / plain FakeBridge tests). A bridge
 * standing in for the native runtime — a test double stubbing a subset
 * of methods, a Jump session that knows what the connected device
 * actually registered — implements this so app code can feature-gate
 * on what the runtime really provides, without core naming any
 * concrete bridge class.
 */
interface GatedBridge
{
    public function can(string $method): bool;
}
