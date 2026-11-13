<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Asgard\ServerActionFailed;

/**
 * FragmentActionScreen, listening for failed server actions: every one,
 * and only those of `like`.
 */
class ActionFailureScreen extends FragmentActionScreen
{
    /** @var list<array> payloads heard by the catch-all listener */
    public array $failures = [];

    /** @var list<string> reasons heard by the `like` listener */
    public array $likeFailures = [];

    /** Seconds; null uses the config. */
    public ?float $timeout = null;

    public function serverActionTimeout(): float
    {
        return $this->timeout ?? parent::serverActionTimeout();
    }

    #[On(ServerActionFailed::class)]
    public function actionFailed(string $action, string $component, ?string $region, string $reason, ?int $status, string $requestId): void
    {
        $this->failures[] = compact('action', 'component', 'region', 'reason', 'status', 'requestId');
    }

    #[On(ServerActionFailed::class, when: ['action' => 'like'])]
    public function likeFailed(string $reason): void
    {
        $this->likeFailures[] = $reason;
    }
}
