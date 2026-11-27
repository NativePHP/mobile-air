<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\PackageCommand;
use Tests\TestCase;

class AndroidBuildNumberTest extends TestCase
{
    protected string $testProjectPath;

    protected string $gradlePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = realpath(sys_get_temp_dir()).'/nativephp_android_build_number_test_'.uniqid();
        $this->gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        File::ensureDirectoryExists(dirname($this->gradlePath));
        File::put($this->gradlePath, "android {\n    versionCode = REPLACEMECODE\n    versionName = \"REPLACEME\"\n}\n");
        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        putenv('NATIVEPHP_APP_VERSION_CODE');
        File::deleteDirectory($this->testProjectPath);

        parent::tearDown();
    }

    public function test_local_bump_puts_the_same_version_code_in_gradle_env_and_config(): void
    {
        $this->givenVersionCode('5');

        $this->updateVersionConfiguration();

        $this->assertVersionCode(6);
    }

    public function test_first_local_build_puts_version_code_one_in_gradle_env_and_config(): void
    {
        // Unset, the code falls back to config's default of 1 and masks the stale value.
        $this->givenVersionCode('0');

        $this->updateVersionConfiguration();

        $this->assertVersionCode(1);
    }

    private function givenVersionCode(string $code): void
    {
        File::put($this->testProjectPath.'/.env', "NATIVEPHP_APP_VERSION_CODE={$code}\n");
        // env() reads the process environment loaded at boot, not the .env file.
        putenv("NATIVEPHP_APP_VERSION_CODE={$code}");
        config(['nativephp.version_code' => $code]);
    }

    private function updateVersionConfiguration(): void
    {
        $command = app(PackageCommand::class);

        (new \ReflectionMethod($command, 'updateVersionConfiguration'))->invoke($command);
    }

    private function assertVersionCode(int $expected): void
    {
        $this->assertMatchesRegularExpression("/versionCode = {$expected}\\b/", File::get($this->gradlePath));
        $this->assertStringContainsString("NATIVEPHP_APP_VERSION_CODE={$expected}", File::get($this->testProjectPath.'/.env'));
        $this->assertSame($expected, config('nativephp.version_code'));
    }
}
