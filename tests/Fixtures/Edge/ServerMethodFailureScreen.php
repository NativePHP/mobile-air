<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Asgard\ServerActionFailed;

/** ServerMethodScreen, listening for failed `#[Server]` calls. */
class ServerMethodFailureScreen extends ServerMethodScreen
{
    /** @var list<array> */
    public array $failures = [];

    /** @var list<string> */
    public array $pingFailures = [];

    #[On(ServerActionFailed::class)]
    public function actionFailed(string $action, ?string $region, string $reason, ?int $status, string $requestId): void
    {
        $this->failures[] = compact('action', 'region', 'reason', 'status', 'requestId');
    }

    #[On(ServerActionFailed::class, when: ['action' => 'ping'])]
    public function pingFailed(string $reason): void
    {
        $this->pingFailures[] = $reason;
    }
}
