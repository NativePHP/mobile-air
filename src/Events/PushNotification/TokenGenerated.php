<?php

namespace Native\Mobile\Events\PushNotification;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class TokenGenerated implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $token,
        public ?string $id = null
    ) {}
}
