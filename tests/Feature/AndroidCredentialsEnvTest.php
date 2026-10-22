<?php

use Native\Mobile\Concerns\CreatesAndroidCredentials;

function makeAndroidEnvWriter(): object
{
    return new class
    {
        use CreatesAndroidCredentials;

        public array $messages = [];

        public function writeEnvVars(string $keystoreFile, string $keystorePassword, string $keyAlias, string $keyPassword): void
        {
            $this->updateAndroidEnvVars($keystoreFile, $keystorePassword, $keyAlias, $keyPassword);
        }

        protected function info(string $message): void
        {
            $this->messages[] = $message;
        }

        protected function line(string $message): void
        {
            $this->messages[] = $message;
        }

        protected function warn(string $message): void
        {
            $this->messages[] = $message;
        }

        protected function error(string $message): void
        {
            $this->messages[] = $message;
        }

        protected function newLine(): void {}
    };
}

beforeEach(function () {
    $this->envDir = sys_get_temp_dir().'/android-env-test-'.getmypid();
    if (! is_dir($this->envDir)) {
        mkdir($this->envDir, 0755, true);
    }
    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->envDir);
});

afterEach(function () {
    $this->app->setBasePath($this->originalBasePath);
    @unlink($this->envDir.'/.env');
    @rmdir($this->envDir);
});

it('writes keystore env vars double quoted so special characters survive dotenv', function () {
    makeAndroidEnvWriter()->writeEnvVars('upload-keystore.jks', 'p@ss#w0rd', 'app-key', 'key pass$1');

    $parsed = Dotenv\Dotenv::createArrayBacked($this->envDir)->load();

    expect($parsed['ANDROID_KEYSTORE_FILE'])->toBe('upload-keystore.jks')
        ->and($parsed['ANDROID_KEYSTORE_PASSWORD'])->toBe('p@ss#w0rd')
        ->and($parsed['ANDROID_KEY_ALIAS'])->toBe('app-key')
        ->and($parsed['ANDROID_KEY_PASSWORD'])->toBe('key pass$1');
});

it('leaves existing password vars untouched and warns about unquoted values', function () {
    file_put_contents($this->envDir.'/.env', 'ANDROID_KEYSTORE_PASSWORD=old#password'.PHP_EOL);

    $writer = makeAndroidEnvWriter();
    $writer->writeEnvVars('upload-keystore.jks', 'new-password', 'app-key', 'new-password');

    // The pre-existing (broken, unquoted) value must not be overwritten...
    $envContent = file_get_contents($this->envDir.'/.env');
    expect($envContent)->toContain('ANDROID_KEYSTORE_PASSWORD=old#password');

    // ...but the user must be told it was skipped and how to fix it.
    $warning = collect($writer->messages)->first(
        fn (string $m) => str_contains($m, 'ANDROID_KEYSTORE_PASSWORD') && str_contains($m, 'not overwritten')
    );
    expect($warning)->not->toBeNull();

    // Vars that were missing are still added, quoted.
    $parsed = Dotenv\Dotenv::createArrayBacked($this->envDir)->load();
    expect($parsed['ANDROID_KEY_PASSWORD'])->toBe('new-password');
});
