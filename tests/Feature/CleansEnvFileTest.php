<?php

namespace Tests\Feature;

use Native\Mobile\Concerns\CleansEnvFile;
use Tests\TestCase;

/**
 * The bundled .env must never carry the developer's encryption keys: each
 * device generates its own.
 */
class CleansEnvFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function clean(string $contents): string
    {
        file_put_contents($this->path, $contents);

        (new class
        {
            use CleansEnvFile;

            public function run(string $path): void
            {
                $this->cleanEnvFile($path);
            }
        })->run($this->path);

        return file_get_contents($this->path);
    }

    public function test_app_keys_are_stripped_even_when_the_config_does_not_list_them(): void
    {
        config(['nativephp.cleanup_env_keys' => []]);

        $cleaned = $this->clean(implode("\n", [
            'APP_NAME=Test',
            'APP_KEY=base64:abc',
            'export APP_KEY=base64:def',
            ' APP_PREVIOUS_KEYS = base64:ghi',
            'APP_KEYBOARD=qwerty',
        ]));

        $this->assertSame("APP_NAME=Test\nAPP_KEYBOARD=qwerty", $cleaned);
    }

    public function test_configured_keys_are_still_stripped(): void
    {
        config(['nativephp.cleanup_env_keys' => ['AWS_*', '*_SECRET']]);

        $cleaned = $this->clean(implode("\n", [
            '# a comment',
            'AWS_BUCKET=one',
            'STRIPE_SECRET=two',
            'APP_KEY=base64:abc',
            'APP_URL=http://localhost',
        ]));

        $this->assertSame('APP_URL=http://localhost', $cleaned);
    }
}
