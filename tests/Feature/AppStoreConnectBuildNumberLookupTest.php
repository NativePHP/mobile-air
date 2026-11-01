<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The iOS build-number lookup treated any failed App Store Connect request as
 * "no builds yet", so the build carried on with a locally guessed number and
 * Apple rejected the upload as a duplicate. The most common failure was a 401
 * from our own token: it was valid for exactly 20 minutes, Apple's maximum, so
 * a build machine whose clock ran a second fast was refused.
 */
class AppStoreConnectBuildNumberLookupTest extends TestCase
{
    private string $keyPath;

    /** @var array<string, int> */
    private array $calls = [];

    /** @var array<int, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_STORE_API_KEY', 'APP_STORE_API_KEY_PATH', 'APP_STORE_API_KEY_ID', 'APP_STORE_API_ISSUER_ID'] as $key) {
            putenv($key);
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $this->keyPath = tempnam(sys_get_temp_dir(), 'asc').'.p8';
        file_put_contents($this->keyPath, $pem);

        config([
            'nativephp.app_id' => 'com.example.app',
            'nativephp.version' => '1.2.0',
            'nativephp.app_store_connect' => [
                'api_key' => $this->keyPath,
                'api_key_id' => 'ABC123',
                'api_issuer_id' => '69a6de94-0000-0000-0000-000000000000',
            ],
        ]);

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);

        parent::tearDown();
    }

    public function test_the_token_leaves_room_for_a_build_machine_clock_that_runs_fast(): void
    {
        $this->fakeAppStoreConnect([
            '/v1/apps' => [Http::response(['data' => []])],
        ]);

        $this->artisan('native:check-build-number', ['platform' => 'ios'])->assertSuccessful();

        $payload = json_decode(base64_decode(strtr(explode('.', $this->tokens[0])[1], '-_', '+/')), true);

        $this->assertLessThanOrEqual(time() - 60, $payload['iat']);
        $this->assertGreaterThan(time(), $payload['exp']);
        $this->assertLessThanOrEqual(time() + 600, $payload['exp']);
    }

    public function test_a_failed_request_is_retried_once_before_giving_up(): void
    {
        $this->fakeAppStoreConnect([
            '/v1/apps' => [
                Http::response($this->notAuthorized(), 401),
                Http::response(['data' => [['id' => '6400000000']]]),
            ],
            '/v1/apps/6400000000/preReleaseVersions' => [
                Http::response(['data' => [['id' => 'prv-1', 'attributes' => ['version' => '1.2.0']]]]),
            ],
            '/v1/preReleaseVersions/prv-1/builds' => [
                Http::response(['data' => [['attributes' => ['version' => '41']], ['attributes' => ['version' => '42']]]]),
            ],
        ]);

        $this->artisan('native:check-build-number', ['platform' => 'ios'])
            ->expectsOutputToContain('App Store latest: 42')
            ->expectsOutputToContain('Suggested next: 43')
            ->assertSuccessful();

        $this->assertSame(2, $this->calls['/v1/apps']);
    }

    public function test_the_lookup_fails_instead_of_guessing_when_apple_keeps_refusing(): void
    {
        $this->fakeAppStoreConnect([
            '/v1/apps' => [Http::response($this->notAuthorized(), 401)],
        ]);

        $this->artisan('native:check-build-number', ['platform' => 'ios'])
            ->expectsOutputToContain('Could not get the latest build number from App Store Connect')
            // Bifrost reads Apple's error codes (agreements, contracts) from this output.
            ->expectsOutputToContain('NOT_AUTHORIZED')
            ->doesntExpectOutputToContain('Suggested next')
            ->assertFailed();

        $this->assertSame(2, $this->calls['/v1/apps']);
    }

    public function test_a_connection_failure_is_retried_once_and_then_fails(): void
    {
        $this->fakeAppStoreConnect([
            '/v1/apps' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
        ]);

        $this->artisan('native:check-build-number', ['platform' => 'ios'])
            ->expectsOutputToContain('Operation timed out')
            ->doesntExpectOutputToContain('Suggested next')
            ->assertFailed();

        $this->assertSame(2, $this->calls['/v1/apps']);
    }

    public function test_a_failed_pre_release_lookup_is_not_mistaken_for_a_new_version(): void
    {
        $this->fakeAppStoreConnect([
            '/v1/apps' => [Http::response(['data' => [['id' => '6400000000']]])],
            '/v1/apps/6400000000/preReleaseVersions' => [Http::response(['errors' => [['status' => '503']]], 503)],
        ]);

        $this->artisan('native:check-build-number', ['platform' => 'ios'])
            ->expectsOutputToContain('Could not get preReleaseVersions')
            ->doesntExpectOutputToContain('no builds yet')
            ->assertFailed();

        $this->assertSame(2, $this->calls['/v1/apps/6400000000/preReleaseVersions']);
    }

    public function test_an_app_missing_from_app_store_connect_is_still_an_answer(): void
    {
        $this->fakeAppStoreConnect([
            '/v1/apps' => [Http::response(['data' => []])],
        ]);

        $this->artisan('native:check-build-number', ['platform' => 'ios'])
            ->expectsOutputToContain('not found in App Store Connect')
            ->assertSuccessful();

        $this->assertSame(1, $this->calls['/v1/apps']);
    }

    /**
     * Answer each App Store Connect path with its responses in turn, repeating
     * the last one once they run out.
     *
     * @param  array<string, array<int, mixed>>  $routes
     */
    private function fakeAppStoreConnect(array $routes): void
    {
        Http::fake(function (Request $request) use ($routes) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->calls[$path] = ($this->calls[$path] ?? 0) + 1;
            $this->tokens[] = str_replace('Bearer ', '', $request->header('Authorization')[0]);

            $answers = $routes[$path] ?? throw new \RuntimeException("Unexpected request to {$path}");
            $answer = $answers[min($this->calls[$path], count($answers)) - 1];

            return $answer instanceof \Closure ? $answer() : $answer;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function notAuthorized(): array
    {
        return ['errors' => [[
            'status' => '401',
            'code' => 'NOT_AUTHORIZED',
            'title' => 'Authentication credentials are missing or invalid.',
        ]]];
    }
}
