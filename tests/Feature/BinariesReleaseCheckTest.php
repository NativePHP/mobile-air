<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\RunCommand;
use Native\Mobile\Support\InstalledBinaries;
use Native\Mobile\Support\PhpBinaries;
use Tests\TestCase;

/**
 * `native:run` says when the PHP binaries in the platform project aren't the
 * release this version of nativephp/mobile installs.
 *
 * The build itself is stubbed out, and so is `native:install`, which records
 * the release the way a successful install does.
 */
class BinariesReleaseCheckTest extends TestCase
{
    protected string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_binaries_check_test_'.uniqid();
        File::ensureDirectoryExists($this->projectPath.'/nativephp/android');
        app()->setBasePath($this->projectPath);

        FakeInstallCommand::$calls = [];
        FakeInstallCommand::$exitCode = 0;

        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new RunCommandWithoutBuild);
        $kernel->registerCommand(new FakeInstallCommand);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_it_says_nothing_when_the_installed_release_matches(): void
    {
        InstalledBinaries::record($this->projectPath.'/nativephp/android');

        $this->artisan('native:run', ['os' => 'android'])
            ->doesntExpectOutputToContain('PHP binaries release')
            ->assertSuccessful();

        $this->assertSame([], FakeInstallCommand::$calls);
    }

    public function test_it_offers_to_reinstall_when_the_release_differs(): void
    {
        $this->writeRecord('3.1.0');

        $this->artisan('native:run', ['os' => 'android'])
            ->expectsOutputToContain('nativephp/android has PHP binaries')
            ->expectsConfirmation('Run `native:install android --force` now?', 'yes')
            ->assertSuccessful();

        $this->assertSame([['platform' => 'android', 'force' => true]], FakeInstallCommand::$calls);
        $this->assertSame(InstalledBinaries::expected(), InstalledBinaries::read($this->projectPath.'/nativephp/android'));
    }

    public function test_the_warning_names_both_releases(): void
    {
        $this->writeRecord('3.1.0');

        // Without interaction the prompt takes its default and reinstalls.
        Artisan::call('native:run', ['os' => 'android', '--no-interaction' => true]);

        // The warning wraps to the terminal width, so compare it as one line.
        $output = preg_replace('/\s+/', ' ', Artisan::output());

        $this->assertStringContainsString(
            'nativephp/android has PHP binaries release 3.1.0, but this version of nativephp/mobile installs '.PhpBinaries::VERSION.'.',
            $output
        );
        $this->assertSame([['platform' => 'android', 'force' => true]], FakeInstallCommand::$calls);
    }

    /**
     * Declining carries on with the old binaries rather than stopping. The
     * record can't say whether the difference matters, and running old
     * binaries on purpose is legitimate.
     */
    public function test_declining_the_reinstall_still_runs_the_app(): void
    {
        $this->writeRecord('3.1.0');

        $this->artisan('native:run', ['os' => 'android'])
            ->expectsConfirmation('Run `native:install android --force` now?', 'no')
            ->expectsOutputToContain('Building with release 3.1.0')
            ->assertSuccessful();

        $this->assertSame([], FakeInstallCommand::$calls);
        $this->assertTrue(RunCommandWithoutBuild::$built);
    }

    public function test_a_failed_reinstall_stops_the_run(): void
    {
        $this->writeRecord('3.1.0');
        FakeInstallCommand::$exitCode = 1;

        $this->artisan('native:run', ['os' => 'android'])
            ->expectsConfirmation('Run `native:install android --force` now?', 'yes')
            ->expectsOutputToContain('native:install --force failed')
            ->assertFailed();

        $this->assertFalse(RunCommandWithoutBuild::$built);
    }

    /**
     * A branch build and a main build share a release number, so binaries
     * from the wrong branch are a mismatch too.
     */
    public function test_binaries_from_another_branch_are_a_mismatch(): void
    {
        $this->writeRecord(PhpBinaries::VERSION, 'feat/opcache');

        $this->artisan('native:run', ['os' => 'android'])
            ->expectsOutputToContain('feat/opcache branch')
            ->expectsConfirmation('Run `native:install android --force` now?', 'no')
            ->assertSuccessful();
    }

    /**
     * Every project installed before the release was recorded. It can't be
     * compared, so it says so and carries on, without a prompt.
     */
    public function test_it_says_when_it_cannot_tell_which_release_is_installed(): void
    {
        $this->artisan('native:run', ['os' => 'android'])
            ->expectsOutputToContain("Can't tell which PHP binaries release")
            ->expectsOutputToContain('native:install --force')
            ->assertSuccessful();

        $this->assertSame([], FakeInstallCommand::$calls);
        $this->assertTrue(RunCommandWithoutBuild::$built);
    }

    public function test_it_says_nothing_about_a_platform_that_was_never_installed(): void
    {
        File::deleteDirectory($this->projectPath.'/nativephp/android');

        $this->artisan('native:run', ['os' => 'android'])
            ->doesntExpectOutputToContain('PHP binaries release')
            ->assertSuccessful();
    }

    private function writeRecord(string $release, string $branch = 'main'): void
    {
        File::put(
            $this->projectPath.'/nativephp/android/'.InstalledBinaries::FILE,
            json_encode(['release' => $release, 'branch' => $branch])
        );
    }
}

class RunCommandWithoutBuild extends RunCommand
{
    public static bool $built = false;

    public function __construct()
    {
        parent::__construct();

        self::$built = false;
    }

    public function runAndroid(): bool
    {
        return self::$built = true;
    }

    public function runIos(): bool
    {
        return self::$built = true;
    }
}

class FakeInstallCommand extends Command
{
    /** @var array<int, array{platform: ?string, force: bool}> */
    public static array $calls = [];

    public static int $exitCode = 0;

    protected $signature = 'native:install {platform?} {--force}';

    public function handle(): int
    {
        self::$calls[] = [
            'platform' => $this->argument('platform'),
            'force' => (bool) $this->option('force'),
        ];

        if (self::$exitCode === 0) {
            InstalledBinaries::record(base_path('nativephp/'.$this->argument('platform')));
        }

        return self::$exitCode;
    }
}
