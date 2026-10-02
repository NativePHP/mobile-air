<?php

namespace Tests\Unit\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Concerns\PublishesToPlayStore;
use Tests\TestCase;

class PublishesToPlayStoreTest extends TestCase
{
    use PublishesToPlayStore;

    /** @var list<string> */
    protected array $logged = [];

    protected function info($string, $verbosity = null)
    {
        $this->logged[] = 'info:'.$string;
    }

    protected function error($string, $verbosity = null)
    {
        $this->logged[] = 'error:'.$string;
    }

    protected function warn($string, $verbosity = null)
    {
        $this->logged[] = 'warn:'.$string;
    }

    protected function playStoreConfig(): array
    {
        return [
            'package_name' => 'com.example.app',
        ];
    }

    protected function commitUrl(): string
    {
        return 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.example.app/edits/edit-123:commit';
    }

    protected function autoReviewRequiredErrorBody(bool $wrapped = true): array
    {
        $payload = [
            'code' => 400,
            'message' => 'Changes cannot be sent for review automatically. Please set the query parameter changesNotSentForReview to true. Once committed, the changes in this edit can be sent for review from the Google Play Console UI.',
            'status' => 'INVALID_ARGUMENT',
        ];

        return $wrapped ? ['error' => $payload] : $payload;
    }

    protected function assertCommitRequests(int $expectedCount, bool $expectRetryWithFlag): void
    {
        Http::assertSentCount($expectedCount);

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);

        $this->assertSame('POST', $requests[0]->method());
        $this->assertSame($this->commitUrl(), $requests[0]->url());

        if ($expectRetryWithFlag) {
            $this->assertSame('POST', $requests[1]->method());
            parse_str((string) parse_url($requests[1]->url(), PHP_URL_QUERY), $query);
            $this->assertSame('true', $query['changesNotSentForReview'] ?? null);
            $this->assertSame(
                $this->commitUrl(),
                strtok($requests[1]->url(), '?')
            );
        }
    }

    public function test_commit_retries_once_with_changes_not_sent_for_review_on_specific_400(): void
    {
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            if (! str_contains($request->url(), 'changesNotSentForReview')) {
                return Http::response($this->autoReviewRequiredErrorBody(), 400);
            }

            return Http::response(['id' => 'edit-123'], 200);
        });

        $result = $this->commitPlayStoreEdit($this->playStoreConfig(), 'test-token', 'edit-123');

        $this->assertTrue($result);
        $this->assertSame(2, $calls);
        $this->assertCommitRequests(2, expectRetryWithFlag: true);
        $this->assertTrue(collect($this->logged)->contains(fn ($line) => str_starts_with($line, 'warn:')));
    }

    public function test_commit_does_not_retry_on_unrelated_400(): void
    {
        Http::fake(function (Request $request) {
            return Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'The edit is no longer valid.',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400);
        });

        $result = $this->commitPlayStoreEdit($this->playStoreConfig(), 'test-token', 'edit-123');

        $this->assertFalse($result);
        $this->assertCommitRequests(1, expectRetryWithFlag: false);
        $this->assertFalse(collect($this->logged)->contains(fn ($line) => str_starts_with($line, 'warn:')));
    }

    public function test_commit_succeeds_without_flag_when_first_attempt_ok(): void
    {
        Http::fake(function (Request $request) {
            return Http::response(['id' => 'edit-123'], 200);
        });

        $result = $this->commitPlayStoreEdit($this->playStoreConfig(), 'test-token', 'edit-123');

        $this->assertTrue($result);
        $this->assertCommitRequests(1, expectRetryWithFlag: false);
    }

    public function test_commit_returns_false_when_retry_also_fails(): void
    {
        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'changesNotSentForReview')) {
                return Http::response($this->autoReviewRequiredErrorBody(), 400);
            }

            return Http::response([
                'error' => [
                    'message' => 'Still failing',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400);
        });

        $result = $this->commitPlayStoreEdit($this->playStoreConfig(), 'test-token', 'edit-123');

        $this->assertFalse($result);
        $this->assertCommitRequests(2, expectRetryWithFlag: true);
        $this->assertTrue(collect($this->logged)->contains(fn ($line) => str_starts_with($line, 'error:')));
    }

    public function test_commit_retries_on_legacy_unwrapped_error_shape(): void
    {
        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'changesNotSentForReview')) {
                return Http::response($this->autoReviewRequiredErrorBody(wrapped: false), 400);
            }

            return Http::response(['id' => 'edit-123'], 200);
        });

        $result = $this->commitPlayStoreEdit($this->playStoreConfig(), 'test-token', 'edit-123');

        $this->assertTrue($result);
        $this->assertCommitRequests(2, expectRetryWithFlag: true);
    }
}
