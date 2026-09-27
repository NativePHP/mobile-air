<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use Native\Mobile\Concerns\PreparesBuild;
use Tests\TestCase;

class GradleTimeoutTest extends TestCase
{
    protected string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Uses a shell script as the Gradle wrapper.');
        }

        $this->projectPath = realpath(sys_get_temp_dir()).'/nativephp_gradle_timeout_test_'.uniqid();
        File::ensureDirectoryExists($this->projectPath.'/nativephp/android');
        app()->setBasePath($this->projectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_gradle_has_no_timeout_by_default(): void
    {
        config(['nativephp.android.gradle_timeout' => null]);
        $this->assertNull((new GradleTimeoutTester)->timeout());

        config(['nativephp.android.gradle_timeout' => '']);
        $this->assertNull((new GradleTimeoutTester)->timeout());

        config(['nativephp.android.gradle_timeout' => '0']);
        $this->assertNull((new GradleTimeoutTester)->timeout());
    }

    public function test_gradle_timeout_can_be_set_in_seconds(): void
    {
        config(['nativephp.android.gradle_timeout' => '1800']);

        $this->assertSame(1800, (new GradleTimeoutTester)->timeout());
    }

    public function test_the_gradle_process_is_not_capped_by_default(): void
    {
        // 600 seconds used to be hardcoded, which a cold first build overran.
        config(['nativephp.android.gradle_timeout' => null]);
        $this->assertNull((new GradleTimeoutTester)->process()->timeout);

        config(['nativephp.android.gradle_timeout' => 1800]);
        $this->assertSame(1800, (new GradleTimeoutTester)->process()->timeout);
    }

    public function test_a_gradle_build_runs_through_the_wrapper(): void
    {
        config(['nativephp.android.gradle_timeout' => null]);
        $this->writeGradleWrapper('[ "$1" = assembleDebug ] || exit 3; exit 0');

        $builder = new RecordingGradleTimeoutTester;

        $this->assertTrue($builder->build('assembleDebug'));
        $this->assertSame([], $builder->timeoutsReported);
    }

    public function test_a_gradle_build_that_hits_the_configured_timeout_fails_with_a_way_forward(): void
    {
        config(['nativephp.android.gradle_timeout' => 1]);
        $this->writeGradleWrapper('sleep 10; exit 0');

        $builder = new RecordingGradleTimeoutTester;
        $started = microtime(true);

        $this->assertFalse($builder->build('assembleDebug'));
        $this->assertLessThan(8, microtime(true) - $started);
        $this->assertSame(['assembleDebug'], $builder->timeoutsReported);
    }

    public function test_the_timeout_message_points_at_the_gradlew_command(): void
    {
        config(['nativephp.android.gradle_timeout' => 900]);
        Prompt::fake();

        (new GradleTimeoutTester)->report('assembleDebug');

        Prompt::assertOutputContains('Gradle did not finish within 900 seconds');
        Prompt::assertOutputContains('cd nativephp/android && ./gradlew assembleDebug');
        Prompt::assertOutputContains('NATIVEPHP_GRADLE_TIMEOUT');
    }

    protected function writeGradleWrapper(string $body): void
    {
        $path = $this->projectPath.'/nativephp/android/gradlew';
        File::put($path, "#!/bin/sh\n{$body}\n");
        chmod($path, 0755);
    }
}

class GradleTimeoutTester
{
    use PreparesBuild;

    public object $components;

    public function __construct()
    {
        $this->components = new class
        {
            public function task(string $title, callable $callback): mixed
            {
                return $callback();
            }

            public function twoColumnDetail(...$args): void {}
        };
    }

    public function timeout(): ?int
    {
        return $this->gradleTimeout();
    }

    public function process(): PendingProcess
    {
        return $this->gradleProcess(base_path('nativephp/android'));
    }

    public function build(string $task): bool
    {
        return $this->executeGradleBuild($task);
    }

    public function option(string $name): mixed
    {
        return $name === 'no-tty' ? true : null;
    }

    public function report(string $task): void
    {
        $this->reportGradleTimeout($task);
    }

    protected function copyMappingFilesToOutput(string $gradleTask): void {}

    protected function newLine(): void {}

    protected function logToFile(string $message): void {}

    protected function removeDirectory(string $path): void {}

    protected function detectCurrentAppId(): ?string
    {
        return null;
    }

    protected function updateAppId(string $oldAppId, string $newAppId): void {}

    protected function updateLocalProperties(): void {}

    protected function updateVersionConfiguration(): void {}

    protected function updateAppDisplayName(): void {}

    protected function updateDeepLinkConfiguration(): void {}

    protected function updatePermissions(): void {}

    protected function updateReleaseAudience(): void {}

    protected function updateIcuConfiguration(): void {}
}

class RecordingGradleTimeoutTester extends GradleTimeoutTester
{
    /** @var array<int, string> */
    public array $timeoutsReported = [];

    protected function reportGradleTimeout(string $gradleTask): void
    {
        $this->timeoutsReported[] = $gradleTask;
    }
}
