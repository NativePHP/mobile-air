<?php

namespace Tests\Feature;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Encryption\Encrypter;
use Native\Mobile\NativeServiceProvider;
use Native\Mobile\Support\DeviceAppKey;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * On a device the native shell owns APP_KEY and passes it through the
 * environment. The keys must reach Laravel from there and stay out of the
 * config cache Android builds on the device.
 */
class DeviceAppKeyTest extends TestCase
{
    private string $current;

    private string $previous;

    private ?string $cachePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->current = 'base64:'.base64_encode(random_bytes(32));
        $this->previous = 'base64:'.base64_encode(random_bytes(32));
    }

    protected function tearDown(): void
    {
        putenv('APP_KEY');
        putenv('APP_PREVIOUS_KEYS');

        if ($this->cachePath) {
            @unlink($this->cachePath);
        }

        parent::tearDown();
    }

    private function registerOnDevice(): void
    {
        config(['nativephp-internal.running' => true]);

        (new NativeServiceProvider($this->app))->register();
    }

    private function cacheFile(array $config): string
    {
        $this->cachePath = tempnam(sys_get_temp_dir(), 'config');
        file_put_contents($this->cachePath, '<?php return '.var_export($config, true).';'.PHP_EOL);

        return $this->cachePath;
    }

    public function test_keys_come_from_the_environment_on_a_device(): void
    {
        putenv("APP_KEY={$this->current}");
        putenv("APP_PREVIOUS_KEYS={$this->previous}");
        config(['app.key' => null, 'app.previous_keys' => []]);

        $this->registerOnDevice();

        $this->assertSame($this->current, config('app.key'));
        $this->assertSame([$this->previous], config('app.previous_keys'));
    }

    public function test_data_encrypted_with_the_retired_key_still_decrypts(): void
    {
        if (! method_exists(Encrypter::class, 'previousKeys')) {
            $this->markTestSkipped('APP_PREVIOUS_KEYS needs Laravel 11 or newer.');
        }

        $old = new Encrypter(base64_decode(substr($this->previous, 7)), 'AES-256-CBC');
        $payload = $old->encryptString('secret');

        putenv("APP_KEY={$this->current}");
        putenv("APP_PREVIOUS_KEYS={$this->previous}");

        $this->registerOnDevice();
        $this->app->forgetInstance('encrypter');

        $this->assertSame('secret', $this->app['encrypter']->decryptString($payload));
        $this->assertSame($this->current, 'base64:'.base64_encode($this->app['encrypter']->getKey()));
    }

    public function test_an_empty_previous_keys_variable_clears_the_configured_ones(): void
    {
        putenv("APP_KEY={$this->current}");
        putenv('APP_PREVIOUS_KEYS=');
        config(['app.previous_keys' => [$this->previous]]);

        $this->registerOnDevice();

        $this->assertSame([], config('app.previous_keys'));
    }

    public function test_config_is_left_alone_off_device(): void
    {
        putenv("APP_KEY={$this->current}");
        config(['nativephp-internal.running' => false, 'app.key' => 'configured']);

        (new NativeServiceProvider($this->app))->register();

        $this->assertSame('configured', config('app.key'));
    }

    public function test_config_is_left_alone_without_a_key_in_the_environment(): void
    {
        putenv('APP_KEY');
        config(['app.key' => 'configured']);

        $this->registerOnDevice();

        $this->assertSame('configured', config('app.key'));
    }

    public function test_scrubbing_removes_only_the_keys_from_a_config_cache(): void
    {
        $path = $this->cacheFile([
            'app' => ['name' => 'Test', 'key' => $this->current, 'previous_keys' => [$this->previous]],
            'nativephp-internal' => ['running' => true],
        ]);

        DeviceAppKey::scrubCachedConfig($path);

        $contents = file_get_contents($path);
        $this->assertStringNotContainsString($this->current, $contents);
        $this->assertStringNotContainsString($this->previous, $contents);

        $this->assertSame([
            'app' => ['name' => 'Test', 'key' => null, 'previous_keys' => []],
            'nativephp-internal' => ['running' => true],
        ], require $path);
    }

    public function test_config_cache_is_scrubbed_once_the_command_finishes_on_a_device(): void
    {
        $path = $this->cacheFile(['app' => ['key' => $this->current, 'previous_keys' => []]]);
        putenv('APP_CONFIG_CACHE='.$path);

        try {
            $this->registerOnDevice();

            event(new CommandFinished('config:cache', new ArrayInput([]), new NullOutput, 0));

            $this->assertStringNotContainsString($this->current, file_get_contents($path));
        } finally {
            putenv('APP_CONFIG_CACHE');
        }
    }

    public function test_other_commands_do_not_touch_the_config_cache(): void
    {
        $path = $this->cacheFile(['app' => ['key' => $this->current, 'previous_keys' => []]]);
        putenv('APP_CONFIG_CACHE='.$path);

        try {
            $this->registerOnDevice();

            event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

            $this->assertStringContainsString($this->current, file_get_contents($path));
        } finally {
            putenv('APP_CONFIG_CACHE');
        }
    }
}
