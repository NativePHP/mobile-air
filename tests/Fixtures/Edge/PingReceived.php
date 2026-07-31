<?php

namespace Tests\Fixtures\Edge;

class PingReceived
{
    public function __construct(
        public string $message = '',
        public ?string $id = null,
    ) {}
}
