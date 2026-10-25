<?php

namespace Native\Mobile\Events\App;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class UpdateInstalled implements DispatchableFromNative
{
    use Dispatchable;

    public function __construct(
        public readonly string $version,
        public readonly int $timestamp
    ) {}
}
