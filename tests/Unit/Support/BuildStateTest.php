<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\File;
use Native\Mobile\Support\BuildState;
use Tests\TestCase;

class BuildStateTest extends TestCase
{
    protected string $platformPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformPath = sys_get_temp_dir().'/nativephp_build_state_test_'.uniqid();
        File::ensureDirectoryExists($this->platformPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->platformPath);

        parent::tearDown();
    }

    public function test_a_recorded_step_matches_the_same_fingerprint_in_the_next_build(): void
    {
        BuildState::for($this->platformPath, 'debug')->record('composer', 'abc');

        $state = BuildState::for($this->platformPath, 'debug');

        $this->assertTrue($state->matches('composer', 'abc'));
        $this->assertFalse($state->matches('composer', 'def'));
        $this->assertFalse($state->matches('cocoapods', 'abc'));
        $this->assertFileExists($this->platformPath.'/'.BuildState::FILE);
    }

    public function test_a_null_fingerprint_never_matches(): void
    {
        $state = BuildState::for($this->platformPath, 'debug');
        $state->record('composer', null);

        $this->assertFalse($state->matches('composer', null));
        $this->assertFalse($state->has('composer'));
    }

    public function test_forgetting_a_step_is_persisted_immediately(): void
    {
        $state = BuildState::for($this->platformPath, 'debug');
        $state->record('composer', 'abc');
        $state->record('staging', 'xyz');

        // A build that dies after this point must not see the step as done.
        $state->forget('composer');

        $next = BuildState::for($this->platformPath, 'debug');

        $this->assertFalse($next->matches('composer', 'abc'));
        $this->assertTrue($next->matches('staging', 'xyz'));
    }

    public function test_a_different_build_type_discards_everything(): void
    {
        BuildState::for($this->platformPath, 'debug')->record('composer', 'abc');

        $this->assertFalse(BuildState::for($this->platformPath, 'release')->matches('composer', 'abc'));
    }

    public function test_a_different_package_version_discards_everything(): void
    {
        $file = $this->platformPath.'/'.BuildState::FILE;

        (new BuildState($file, ['schema' => 1, 'package' => '4.5.2@aaa', 'build' => 'debug']))->record('composer', 'abc');

        $this->assertTrue((new BuildState($file, ['schema' => 1, 'package' => '4.5.2@aaa', 'build' => 'debug']))->matches('composer', 'abc'));
        $this->assertFalse((new BuildState($file, ['schema' => 1, 'package' => '4.6.0@bbb', 'build' => 'debug']))->matches('composer', 'abc'));
    }

    public function test_the_context_includes_the_installed_package_version(): void
    {
        $context = BuildState::context('debug');

        $this->assertSame('debug', $context['build']);
        $this->assertNotSame('', $context['package']);
    }

    public function test_fresh_ignores_what_was_recorded(): void
    {
        BuildState::for($this->platformPath, 'debug')->record('composer', 'abc');

        $this->assertFalse(BuildState::for($this->platformPath, 'debug', fresh: true)->matches('composer', 'abc'));
    }

    public function test_a_fresh_build_records_for_the_one_after_it(): void
    {
        BuildState::for($this->platformPath, 'debug')->record('composer', 'old');

        $fresh = BuildState::for($this->platformPath, 'debug', fresh: true);
        $fresh->record('staging', 'new');

        $next = BuildState::for($this->platformPath, 'debug');

        $this->assertTrue($next->matches('staging', 'new'));
        $this->assertFalse($next->matches('composer', 'old'));
    }

    public function test_clear_removes_the_state_file(): void
    {
        BuildState::for($this->platformPath, 'debug')->record('composer', 'abc');

        BuildState::clear($this->platformPath);

        $this->assertFileDoesNotExist($this->platformPath.'/'.BuildState::FILE);
        $this->assertFalse(BuildState::for($this->platformPath, 'debug')->matches('composer', 'abc'));
    }

    public function test_a_corrupt_state_file_is_treated_as_empty(): void
    {
        File::put($this->platformPath.'/'.BuildState::FILE, '{"context": nope');

        $state = BuildState::for($this->platformPath, 'debug');

        $this->assertFalse($state->matches('composer', 'abc'));

        $state->record('composer', 'abc');

        $this->assertTrue(BuildState::for($this->platformPath, 'debug')->matches('composer', 'abc'));
    }

    public function test_nothing_is_written_when_the_platform_project_does_not_exist(): void
    {
        $missing = $this->platformPath.'/missing';

        BuildState::for($missing, 'debug')->record('composer', 'abc');

        $this->assertDirectoryDoesNotExist($missing);
    }

    public function test_native_install_clears_the_state_of_the_platforms_it_installs(): void
    {
        // native:install replaces the platform project (or overwrites its
        // files with --no-force), so nothing the last build remembered about
        // it can be trusted. Asserted on the source because running the
        // installer here would download PHP binaries.
        $source = file_get_contents(
            (new \ReflectionClass(\Native\Mobile\Commands\InstallCommand::class))->getFileName()
        );

        $this->assertMatchesRegularExpression('/foreach \(\$removing as \$platform\) \{\s*BuildState::clear\(/', $source);
    }
}
