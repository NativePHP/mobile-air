<?php

namespace Native\Mobile\Edge\Extensions;

use Closure;
use Native\Mobile\Edge\Contracts\RunloopExtension;
use Native\Mobile\Edge\NativeComponent;
use SupaNative\Core\Edge\Enums\ActionSource;

/** A RunloopExtension that does nothing. Override the hooks you need. */
abstract class RunloopExtensionBase implements RunloopExtension
{
    public function beginFrame(): void {}

    public function endFrame(NativeComponent $root): void {}

    public function nextWake(array $tree): ?int
    {
        return null;
    }

    public function tick(array $tree): bool
    {
        return false;
    }

    public function handleNativeEvent(string $name, mixed $payload): bool
    {
        return false;
    }

    public function resolveCallback(NativeComponent $component, int $id): ?Closure
    {
        return null;
    }

    public function aroundAction(NativeComponent $component, string $method, array $args, ActionSource $source, Closure $next): mixed
    {
        return $next();
    }

    public function componentUnmounted(NativeComponent $component): void {}
}
