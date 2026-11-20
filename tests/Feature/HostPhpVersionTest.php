<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use Native\Mobile\Commands\InstallCommand;
use Native\Mobile\Commands\RunCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Which PHP binaries `native:install` picks for the host, and what
 * `native:run` does when the host and nativephp.lock disagree.
 *
 * The host PHP is whatever runs the tests, so both commands take it from a
 * method these subclasses override. The build and the reinstall are stubbed.
 */
class HostPhpVersionTest extends TestCase
{
    /** The minors the pinned release publishes in these tests. */
    private const RELEASE = ['8.3', '8.4', '8.5', '8.6'];

    protected string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_host_php_test_'.uniqid();
        File::ensureDirectoryExists($this->projectPath);
        app()->setBasePath($this->projectPath);

        RunCommandOnHost::$host = '8.6';
        RunCommandOnHost::$built = false;
        ReinstallSpy::$calls = [];
        ReinstallSpy::$installs = null;

        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new RunCommandOnHost);
        $kernel->registerCommand(new ReinstallSpy);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_a_host_on_8_6_installs_the_8_6_binaries(): void
    {
        $install = new InstallCommandOnHost('8.6');

        $this->assertSame('8.6', $install->detect(self::RELEASE));
    }

    /**
     * Nothing in the package lists the PHP minors. A release that publishes
     * a new one reaches a host running it with no change here.
     */
    public function test_a_host_on_a_minor_the_release_publishes_installs_it(): void
    {
        $install = new InstallCommandOnHost('8.7');

        $this->assertSame('8.7', $install->detect([...self::RELEASE, '8.7']));
    }

    public function test_a_lock_on_8_6_installs_the_8_6_binaries_whatever_the_host(): void
    {
        $this->writeLock('8.6.0');

        $this->assertSame('8.6', (new InstallCommandOnHost('8.4'))->detect(self::RELEASE));
    }

    /**
     * A lock on a minor the release no longer publishes falls back to the
     * host's, rather than failing the install.
     */
    public function test_a_lock_on_a_minor_the_release_dropped_falls_back_to_the_host(): void
    {
        $this->writeLock('8.2.29');

        $this->assertSame('8.4', (new InstallCommandOnHost('8.4'))->detect(self::RELEASE));
    }

    public function test_it_builds_when_the_host_matches_an_8_6_lock(): void
    {
        $this->writeLock('8.6.0');

        $this->artisan('native:run', ['os' => 'android'])
            ->doesntExpectOutputToContain('does not match nativephp.lock')
            ->assertSuccessful();

        $this->assertTrue(RunCommandOnHost::$built);
    }

    /**
     * A host newer than anything the release publishes gets the newest
     * binaries, and the install says so.
     */
    public function test_a_host_newer_than_the_release_installs_the_newest_and_says_so(): void
    {
        $output = new BufferedOutput;
        Prompt::setOutput($output);

        $install = new InstallCommandOnHost('8.7');
        $install->installWith(self::RELEASE);

        $this->assertSame('8.6.0', $this->lockedVersion());

        $said = preg_replace('/\s+/', ' ', $output->fetch());
        $this->assertStringContainsString('has no PHP 8.7 build, so this installs PHP 8.6.', $said);
        $this->assertStringContainsString('It has 8.3, 8.4, 8.5, 8.6.', $said);
    }

    /**
     * `native:run` can't tell offline which minors the release has, so it
     * offers the reinstall. When that bundles something other than the
     * host's minor, it stops before building.
     */
    public function test_a_host_newer_than_the_release_stops_the_run_after_the_reinstall(): void
    {
        $this->writeLock('8.6.0');
        RunCommandOnHost::$host = '8.7';
        ReinstallSpy::$installs = '8.6.0';

        $this->artisan('native:run', ['os' => 'android'])
            ->expectsOutputToContain('Host PHP 8.7 does not match nativephp.lock (8.6.0).')
            ->expectsConfirmation('Re-run `native:install --force` to bundle PHP 8.7 instead?', 'yes')
            ->expectsOutputToContain('has no PHP 8.7 build.')
            ->expectsOutputToContain('Switch your host to PHP 8.6.x and retry.')
            ->assertFailed();

        $this->assertSame([['force' => true]], ReinstallSpy::$calls);
        $this->assertSame('8.6.0', $this->lockedVersion());
        $this->assertFalse(RunCommandOnHost::$built);
    }

    /**
     * An app installed on an 8.6 host before the release had 8.6 got the 8.5
     * binaries. `native:run` offers to bundle 8.6 instead.
     */
    public function test_an_8_6_host_with_an_8_5_lock_is_offered_a_reinstall(): void
    {
        $this->writeLock('8.5.3');

        $this->artisan('native:run', ['os' => 'android'])
            ->expectsOutputToContain('Host PHP 8.6 does not match nativephp.lock (8.5.3).')
            ->expectsConfirmation('Re-run `native:install --force` to bundle PHP 8.6 instead?', 'yes')
            ->assertSuccessful();

        $this->assertSame([['force' => true]], ReinstallSpy::$calls);
        $this->assertSame('8.6', $this->lockedVersion());
        $this->assertTrue(RunCommandOnHost::$built);
    }

    public function test_declining_the_reinstall_stops_the_run(): void
    {
        $this->writeLock('8.5.3');

        $this->artisan('native:run', ['os' => 'android'])
            ->expectsConfirmation('Re-run `native:install --force` to bundle PHP 8.6 instead?', 'no')
            ->assertFailed();

        $this->assertSame([], ReinstallSpy::$calls);
        $this->assertSame('8.5.3', $this->lockedVersion());
        $this->assertFalse(RunCommandOnHost::$built);
    }

    private function writeLock(string $version): void
    {
        File::put($this->projectPath.'/nativephp.lock', json_encode(['php' => ['version' => $version, 'icu' => false]]));
    }

    private function lockedVersion(): ?string
    {
        return json_decode(File::get($this->projectPath.'/nativephp.lock'), true)['php']['version'] ?? null;
    }
}

class InstallCommandOnHost extends InstallCommand
{
    public function __construct(private string $host)
    {
        parent::__construct();
    }

    protected function hostPhpVersion(): string
    {
        return $this->host;
    }

    /** @param  list<string>  $minors  What the release's manifest publishes. */
    public function detect(array $minors): string
    {
        $this->versionsManifest = self::manifest($minors);

        return $this->detectPhpVersion();
    }

    /**
     * The part of an install that picks the binaries and records them in
     * nativephp.lock, against a manifest publishing $minors.
     *
     * @param  list<string>  $minors
     */
    public function installWith(array $minors): void
    {
        $this->versionsManifest = self::manifest($minors);
        $this->phpVersion = $this->detectPhpVersion();
        $this->writeNativephpLock($this->phpVersion, false);
    }

    /** @param  list<string>  $minors */
    private static function manifest(array $minors): array
    {
        $versions = [];
        foreach ($minors as $minor) {
            $versions[$minor] = ['php_version' => $minor.'.0'];
        }

        return ['versions' => $versions];
    }
}

class RunCommandOnHost extends RunCommand
{
    public static string $host = '8.6';

    public static bool $built = false;

    protected function hostPhpVersion(): string
    {
        return self::$host;
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

class ReinstallSpy extends Command
{
    /** @var array<int, array{force: bool}> */
    public static array $calls = [];

    /** The PHP version the reinstall records, or null to leave the lock alone. */
    public static ?string $installs = null;

    protected $signature = 'native:install {platform?} {--force}';

    public function handle(): int
    {
        self::$calls[] = ['force' => (bool) $this->option('force')];

        if (self::$installs !== null) {
            $lock = json_decode(File::get(base_path('nativephp.lock')), true) ?? [];
            $lock['php']['version'] = self::$installs;
            File::put(base_path('nativephp.lock'), json_encode($lock));
        }

        return self::SUCCESS;
    }
}
