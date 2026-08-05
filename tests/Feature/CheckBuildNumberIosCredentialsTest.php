<?php

namespace Tests\Feature;

use Native\Mobile\Commands\CheckBuildNumberCommand;
use Tests\TestCase;

/**
 * `native:check-build-number ios` read three options its signature never
 * declared, so Symfony threw before the command reached Apple. The exception was
 * swallowed and reported as "No releases found", which looks exactly like a new
 * app - with "Suggested next: 1" underneath it.
 */
class CheckBuildNumberIosCredentialsTest extends TestCase
{
    /** The options ChecksLatestBuildNumber reads on the iOS path. */
    private const REQUIRED_OPTIONS = ['api-key-path', 'api-key-id', 'api-issuer-id'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_STORE_API_KEY', 'APP_STORE_API_KEY_PATH', 'APP_STORE_API_KEY_ID', 'APP_STORE_API_ISSUER_ID'] as $key) {
            putenv($key);
        }

        config([
            'nativephp.app_id' => 'com.example.app',
            'nativephp.version' => '1.2.0',
            'nativephp.app_store_connect' => ['api_key' => null, 'api_key_id' => null, 'api_issuer_id' => null],
        ]);
    }

    public function test_the_command_declares_every_option_the_ios_check_reads(): void
    {
        $definition = (new CheckBuildNumberCommand)->getDefinition();

        foreach (self::REQUIRED_OPTIONS as $option) {
            $this->assertTrue(
                $definition->hasOption($option),
                "--{$option} is read on the iOS path but not declared, so Symfony throws before Apple is reached.",
            );
        }
    }

    public function test_it_reports_missing_credentials_instead_of_suggesting_one(): void
    {
        $this->artisan('native:check-build-number', ['platform' => 'ios'])
            ->expectsOutputToContain('not checked - no API credentials')
            ->doesntExpectOutputToContain('Suggested next: 1')
            ->assertSuccessful();
    }

    public function test_it_does_not_touch_the_build_number_without_credentials(): void
    {
        // --update used to be unsafe to reach for: the output said 1, so it read
        // as though the local build number was about to become 1.
        putenv('NATIVEPHP_APP_VERSION_CODE=28');

        $this->artisan('native:check-build-number', ['platform' => 'ios', '--update' => true])
            ->expectsOutputToContain('Local current: 28')
            ->assertSuccessful();

        $this->assertSame('28', getenv('NATIVEPHP_APP_VERSION_CODE'));

        putenv('NATIVEPHP_APP_VERSION_CODE');
    }

    public function test_it_finds_the_key_path_from_the_published_config(): void
    {
        // config/nativephp.php exposes it as app_store_connect.api_key, fed by
        // APP_STORE_API_KEY - not the APP_STORE_API_KEY_PATH the concern used to
        // be the only fallback for.
        $key = tempnam(sys_get_temp_dir(), 'asc').'.p8';
        file_put_contents($key, 'not a real key');

        config([
            'nativephp.app_store_connect' => [
                'api_key' => $key,
                'api_key_id' => 'ABC123',
                'api_issuer_id' => '69a6de94-0000-0000-0000-000000000000',
            ],
        ]);

        // Proof that the lookup was actually attempted: the version line is only
        // printed once credential resolution has succeeded. The key is not a real
        // one, so it gets no further than that - which is the point.
        $this->artisan('native:check-build-number', ['platform' => 'ios'])
            ->expectsOutputToContain('1.2.0')
            ->doesntExpectOutputToContain('not checked - no API credentials')
            ->assertSuccessful();

        unlink($key);
    }
}
