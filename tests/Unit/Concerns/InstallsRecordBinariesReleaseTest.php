<?php

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\File;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;
use Native\Mobile\Concerns\InstallsAndroid;
use Native\Mobile\Concerns\InstallsIos;
use Native\Mobile\Concerns\TracksInstallFailures;
use Native\Mobile\Support\InstalledBinaries;
use Native\Mobile\Support\PhpBinaries;
use Tests\TestCase;
use ZipArchive;

/**
 * The installers record the binaries release only once the binaries are in
 * place.
 *
 * Runs the real install methods against archives already in the download
 * cache, so nothing is fetched.
 */
class InstallsRecordBinariesReleaseTest extends TestCase
{
    protected string $projectPath;

    protected BinariesInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_install_record_test_'.uniqid();
        File::ensureDirectoryExists($this->projectPath);
        app()->setBasePath($this->projectPath);

        // failInstall() prints through Laravel Prompts.
        Prompt::setOutput(new BufferedConsoleOutput);

        $this->installer = new BinariesInstaller;
        $this->installer->versionsManifest = [
            'versions' => [
                '8.4' => [
                    'php_version' => '8.4.26',
                    'ios' => [$this->archiveUrl('ios')],
                    'android' => [$this->archiveUrl('android')],
                ],
            ],
        ];

        File::ensureDirectoryExists($this->projectPath.'/nativephp/ios');
        File::ensureDirectoryExists($this->projectPath.'/nativephp/android');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_installing_ios_binaries_records_the_release(): void
    {
        $this->cacheArchive('ios', ['Libraries/libphp.a', 'Include/php/main/php.h']);

        $this->installer->installIos();

        $this->assertSame([], $this->installer->failures());
        $this->assertFileExists($this->projectPath.'/nativephp/ios/Libraries/libphp.a');
        $this->assertSame(InstalledBinaries::expected(), InstalledBinaries::read($this->projectPath.'/nativephp/ios'));
    }

    public function test_installing_android_binaries_records_the_release(): void
    {
        $this->cacheArchive('android', ['staticLibs/arm64-v8a/libphp.a', 'include/php/main/php.h']);

        $this->installer->installAndroid();

        $this->assertSame([], $this->installer->failures());
        $this->assertFileExists($this->projectPath.'/nativephp/android/app/src/main/staticLibs/arm64-v8a/libphp.a');
        $this->assertSame(InstalledBinaries::expected(), InstalledBinaries::read($this->projectPath.'/nativephp/android'));
    }

    /**
     * An install that stops before the binaries are in place must not leave
     * the old record behind, describing binaries that may no longer be there.
     */
    public function test_a_failed_install_leaves_no_record(): void
    {
        $this->writeRecord('ios', '3.1.0');
        $this->writeRecord('android', '3.1.0');

        // No binaries for the PHP version being installed.
        $this->installer->phpVersion = '8.3';

        $this->installer->installIos();
        $this->installer->installAndroid();

        $this->assertCount(2, $this->installer->failures());
        $this->assertNull(InstalledBinaries::read($this->projectPath.'/nativephp/ios'));
        $this->assertNull(InstalledBinaries::read($this->projectPath.'/nativephp/android'));
    }

    public function test_a_failed_manifest_fetch_leaves_no_record(): void
    {
        $this->writeRecord('ios', '3.1.0');

        $this->installer->versionsManifest = null;

        $this->installer->installIos();

        $this->assertNull(InstalledBinaries::read($this->projectPath.'/nativephp/ios'));
    }

    /**
     * `native:install ios` leaves the Android project alone, binaries and
     * all, so its record has to stay as it was.
     */
    public function test_installing_one_platform_leaves_the_others_record_alone(): void
    {
        $this->writeRecord('android', '3.1.0');
        $this->cacheArchive('ios', ['Libraries/libphp.a', 'Include/php/main/php.h']);

        $this->installer->installIos();

        $this->assertSame(InstalledBinaries::expected(), InstalledBinaries::read($this->projectPath.'/nativephp/ios'));
        $this->assertSame(
            ['release' => '3.1.0', 'branch' => 'main'],
            InstalledBinaries::read($this->projectPath.'/nativephp/android')
        );
    }

    private function archiveUrl(string $platform): string
    {
        return PhpBinaries::HOST.'/main/'.$platform.'-'.PhpBinaries::VERSION.'-php8.4.26.zip';
    }

    /**
     * @param  string[]  $files
     */
    private function cacheArchive(string $platform, array $files): void
    {
        File::ensureDirectoryExists(PhpBinaries::cacheDirectory());

        $zip = new ZipArchive;
        $zip->open(
            PhpBinaries::cacheDirectory().'/'.basename($this->archiveUrl($platform)),
            ZipArchive::CREATE | ZipArchive::OVERWRITE
        );

        foreach ($files as $file) {
            $zip->addFromString($file, $file);
        }

        $zip->close();
    }

    private function writeRecord(string $platform, string $release): void
    {
        File::put(
            $this->projectPath.'/nativephp/'.$platform.'/'.InstalledBinaries::FILE,
            json_encode(['release' => $release, 'branch' => 'main'])
        );
    }
}

/**
 * Just enough of InstallCommand to run the binary installers on their own.
 */
class BinariesInstaller
{
    use InstallsAndroid, InstallsIos, TracksInstallFailures;

    public string $phpVersion = '8.4';

    public ?array $versionsManifest = null;

    protected bool $forcing = true;

    public object $components;

    public function __construct()
    {
        $this->components = new class
        {
            public function task(string $title, callable $callback): mixed
            {
                return $callback();
            }

            public function twoColumnDetail(mixed ...$args): void {}

            public function warn(mixed ...$args): void {}
        };
    }

    public function option(string $key): mixed
    {
        return false;
    }

    public function installIos(): void
    {
        $this->iosPath = base_path('nativephp/ios');
        $this->installPHPIos();
    }

    public function installAndroid(): void
    {
        $this->installPHPAndroid();
    }

    /** @return string[] */
    public function failures(): array
    {
        return $this->installFailures;
    }
}
