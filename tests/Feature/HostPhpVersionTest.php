<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\InstallCommand;
use Native\Mobile\Commands\RunCommand;
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

        $this->assertSame('8.6', $install->detect());
    }

    public function test_a_lock_on_8_6_installs_the_8_6_binaries_whatever_the_host(): void
    {
        $this->writeLock('8.6.0');

        $this->assertSame('8.6', (new InstallCommandOnHost('8.4'))->detect());
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
     * A host newer than anything supported gets the newest binaries, which
     * then don't match it. `native:run` has to stop there rather than offer
     * a reinstall that would pick the same binaries again.
     */
    public function test_a_host_newer_than_anything_supported_installs_the_newest_and_then_cannot_run(): void
    {
        $install = new InstallCommandOnHost('8.7');
        $install->installWith(['8.6' => ['php_version' => '8.6.0']]);

        $this->assertSame('8.6.0', $this->lockedVersion());

        RunCommandOnHost::$host = '8.7';

        $this->assertSame(1, Artisan::call('native:run', ['os' => 'android']));

        $output = preg_replace('/\s+/', ' ', Artisan::output());
        $this->assertStringContainsString('Host PHP 8.7 does not match nativephp.lock (8.6.0).', $output);
        $this->assertStringContainsString('Your host PHP 8.7 is not supported by NativePHP. Switch your host to PHP 8.6.x and retry.', $output);
        $this->assertSame([], ReinstallSpy::$calls);
        $this->assertFalse(RunCommandOnHost::$built);
    }

    /**
     * An app installed on an 8.6 host before 8.6 was supported got the 8.5
     * binaries. Now the host is supported, so `native:run` can offer to
     * bundle 8.6 instead of telling the developer to switch PHP.
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

    public function detect(): string
    {
        return $this->detectPhpVersion();
    }

    /**
     * The part of an install that picks the binaries and records them in
     * nativephp.lock, against a manifest listing $versions.
     */
    public function installWith(array $versions): void
    {
        $this->phpVersion = $this->detectPhpVersion();
        $this->versionsManifest = ['versions' => $versions];
        $this->writeNativephpLock($this->phpVersion, false);
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

    protected $signature = 'native:install {platform?} {--force}';

    public function handle(): int
    {
        self::$calls[] = ['force' => (bool) $this->option('force')];

        return self::SUCCESS;
    }
}
