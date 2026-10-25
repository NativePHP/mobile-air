<?php

namespace Native\Mobile\Events\Wallet;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class PaymentFailed implements DispatchableFromNative
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $paymentIntentId,
        public string $errorCode,
        public string $errorMessage,
        public ?array $metadata = null
    ) {}
}
