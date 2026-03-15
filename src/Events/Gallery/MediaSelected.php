<?php

namespace Native\Mobile\Events\Gallery;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MediaSelected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public bool $success,
        public array $files = [],
        public int $count = 0,
        public ?string $error = null,
        public bool $cancelled = false,
        public ?string $id = null
    ) {}
}
