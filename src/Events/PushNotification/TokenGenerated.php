<?php

namespace Native\Mobile\Events\PushNotification;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TokenGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $token,
        public ?string $id = null
    ) {}
}
