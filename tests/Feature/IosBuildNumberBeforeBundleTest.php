<?php

namespace Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Commands\BuildIosAppCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The iOS build zipped the Laravel app, then asked App Store Connect for the
 * build number. The runtime tells builds apart by the version code inside the
 * bundle, so with the lookup running after the zip was sealed every TestFlight
 * upload of the same version shipped an identical identity and the device kept
 * running the previously extracted code.
 *
 * The probe answers the macOS check itself so the handle()-level ordering
 * tests run on Linux CI, where the real command would bail before bundling.
 */
class IosBuildNumberBeforeBundleTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = realpath(sys_get_temp_dir()).'/nativephp_ios_build_number_test_'.uniqid();
        File::ensureDirectoryExists($this->testProjectPath);
        app()->setBasePath($this->testProjectPath);
        // native:install would have laid this down; handle() writes its log here.
        File::ensureDirectoryExists($this->testProjectPath.'/nativephp/ios');

        // Bifrost starts every build from a fresh clone with the code pinned to 0.
        File::put($this->testProjectPath.'/.env', "NATIVEPHP_APP_VERSION=1.2.0\nNATIVEPHP_APP_VERSION_CODE=0\n");
        config(['nativephp.version' => '1.2.0', 'nativephp.version_code' => '0']);

        putenv('NATIVEPHP_XCODE_BUILD');
        Process::fake();
    }

    protected function tearDown(): void
    {
        putenv('NATIVEPHP_XCODE_BUILD');
        File::deleteDirectory($this->testProjectPath);

        parent::tearDown();
    }

    public function test_store_lookup_result_reaches_env_and_config_before_anything_is_bundled(): void
    {
        $command = $this->makeCommand(storeLatestBuild: 41);

        $number = $this->resolveWith($command, ['--release' => true, '--upload-to-app-store' => true]);

        $this->assertSame(42, $number);
        $this->assertSame(42, config('nativephp.version_code'));
        $this->assertStringContainsString('NATIVEPHP_APP_VERSION_CODE=42', File::get($this->testProjectPath.'/.env'));
        $this->assertSame(1, $command->storeLookups);
    }

    public function test_local_increment_updates_config_so_the_bundle_identity_matches_the_ipa(): void
    {
        // Without store credentials the old code bumped .env but left config
        // stale, so bundled.version lagged one build behind CFBundleVersion.
        config(['nativephp.version_code' => 6]);
        $command = $this->makeCommand(storeLatestBuild: null);

        $number = $this->resolveWith($command, ['--release' => true]);

        $this->assertSame(7, $number);
        $this->assertSame(7, config('nativephp.version_code'));
        $this->assertStringContainsString('NATIVEPHP_APP_VERSION_CODE=7', File::get($this->testProjectPath.'/.env'));
        $this->assertSame(0, $command->storeLookups);
    }

    public function test_device_and_simulator_runs_never_touch_the_build_number(): void
    {
        foreach ([['--target' => 'ABC123'], ['--simulated' => true]] as $options) {
            $command = $this->makeCommand(storeLatestBuild: 41);

            $number = $this->resolveWith($command, ['--release' => true, ...$options]);

            $this->assertSame(1, $number);
            $this->assertSame('0', config('nativephp.version_code'));
            $this->assertStringContainsString('NATIVEPHP_APP_VERSION_CODE=0', File::get($this->testProjectPath.'/.env'));
            $this->assertSame(0, $command->storeLookups);
        }
    }

    public function test_a_dotted_build_number_reaches_the_xcode_project_untouched(): void
    {
        // CFBundleVersion accepts "1.5"; the old code interpolated the raw
        // config value, so an int cast here would silently rewrite it.
        config(['nativephp.version_code' => '1.5']);
        $command = $this->makeCommand(storeLatestBuild: null);

        $this->assertSame('1.5', $this->resolveWith($command, ['--target' => 'ABC123']));
    }

    public function test_build_number_is_resolved_before_the_laravel_app_is_bundled(): void
    {
        $command = $this->makeCommand(storeLatestBuild: 41);

        try {
            $this->resolveWith($command, ['--release' => true, '--upload-to-app-store' => true], viaHandle: true);
            $this->fail('bundleLaravelApp() should have been reached');
        } catch (\RuntimeException $e) {
            $this->assertSame('bundled', $e->getMessage());
        }

        $this->assertSame(42, $command->versionCodeWhenBundled);
        $this->assertStringContainsString('NATIVEPHP_APP_VERSION_CODE=42', $command->envWhenBundled);
    }

    public function test_xcode_driven_builds_keep_their_own_build_number(): void
    {
        // Xcode's build phase runs `native:build --release` with this set.
        // Archiving from Xcode must not bump .env or call App Store Connect.
        putenv('NATIVEPHP_XCODE_BUILD=1');
        $command = $this->makeCommand(storeLatestBuild: 41);

        try {
            $this->resolveWith($command, ['--release' => true], viaHandle: true);
            $this->fail('bundleLaravelApp() should have been reached');
        } catch (\RuntimeException $e) {
            $this->assertSame('bundled', $e->getMessage());
        }

        $this->assertSame('0', $command->versionCodeWhenBundled);
        $this->assertStringContainsString('NATIVEPHP_APP_VERSION_CODE=0', $command->envWhenBundled);
        $this->assertSame(0, $command->storeLookups);
    }

    private function makeCommand(?int $storeLatestBuild): IosBuildNumberProbe
    {
        $command = new IosBuildNumberProbe;
        $command->storeLatestBuild = $storeLatestBuild;
        $command->setLaravel($this->app);

        return $command;
    }

    private function resolveWith(IosBuildNumberProbe $command, array $options, bool $viaHandle = false): int|string
    {
        $input = new ArrayInput($options, $command->getDefinition());
        $output = new OutputStyle($input, new BufferedOutput);
        $command->prime($input, $output);

        return $viaHandle ? (int) $command->handle() : $command->resolveBuildNumber();
    }
}

class IosBuildNumberProbe extends BuildIosAppCommand
{
    public ?int $storeLatestBuild = null;

    public int $storeLookups = 0;

    public mixed $versionCodeWhenBundled = null;

    public string $envWhenBundled = '';

    public function prime(InputInterface $input, OutputStyle $output): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->components = new Factory($output);
    }

    public function resolveBuildNumber(): int|string
    {
        return parent::resolveBuildNumber();
    }

    protected function runningOnMacOs(): bool
    {
        return true;
    }

    public function getLatestBuildNumberFromStore(string $platform): ?int
    {
        $this->storeLookups++;

        return $this->storeLatestBuild;
    }

    protected function bundleLaravelApp(): void
    {
        // Snapshot what the bundle would have been sealed with, then stop the
        // build here: everything after this needs Xcode.
        $this->versionCodeWhenBundled = config('nativephp.version_code');
        $this->envWhenBundled = File::get(base_path('.env'));

        throw new \RuntimeException('bundled');
    }
}
