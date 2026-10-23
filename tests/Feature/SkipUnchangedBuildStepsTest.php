<?php

namespace Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Commands\BuildIosAppCommand;
use Native\Mobile\Concerns\PreparesBuild;
use Native\Mobile\Support\BuildState;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use ZipArchive;

/**
 * The second build of an unchanged app skips the expensive preparation
 * steps, and any change to their inputs makes them run again.
 *
 * rsync and zip run for real so the bundle assertions reflect what a build
 * would ship; composer, pod and xcodebuild are faked.
 */
class SkipUnchangedBuildStepsTest extends TestCase
{
    protected string $root;

    /** @var array<int, string> */
    protected array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Build steps are never reused on Windows.');
        }

        $this->root = realpath(sys_get_temp_dir()).'/nativephp_skip_unchanged_test_'.uniqid();
        File::ensureDirectoryExists($this->root);
        app()->setBasePath($this->root);

        config([
            'nativephp.cleanup_exclude_files' => [],
            'nativephp.runtime.mode' => 'persistent',
        ]);

        $this->makeLaravelApp();

        $record = function ($process) {
            $this->ran[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return Process::result();
        };

        Process::fake([
            'composer *' => $record,
            "'composer' *" => $record,
            "'pod' *" => $record,
            "'xcodebuild' *" => $record,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('NATIVEPHP_XCODE_BUILD');
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    // Android

    public function test_android_second_build_skips_composer_and_the_autoloader(): void
    {
        $first = $this->buildAndroid();

        $this->assertSame([
            'composer install --no-interaction',
            'composer dump-autoload --optimize --classmap-authoritative',
        ], $first['ran']);

        $second = $this->buildAndroid();

        $this->assertSame([], $second['ran']);
        $this->assertContains('Installing Composer dependencies: skipped (unchanged)', $second['skipped']);
        $this->assertContains('Optimizing autoloader: skipped (unchanged)', $second['skipped']);
        $this->assertContains('Copying Laravel source (changed files only)', $second['tasks']);

        // The skipped build still ships the whole app.
        $zip = $this->androidZip();
        $this->assertNotFalse($zip->statName('app/Example.php'));
        $this->assertNotFalse($zip->statName('vendor/acme/pkg/src/Pkg.php'));
        $this->assertNotFalse($zip->statName('vendor/autoload.php'));
        $this->assertNotFalse($zip->statName('.env'));
        $this->assertNotFalse($zip->statName('artisan.php'));
        $this->assertFalse($zip->statName('composer.lock'));
        $zip->close();
    }

    public function test_android_app_class_change_rebuilds_only_the_autoloader(): void
    {
        $this->buildAndroid();

        File::put($this->root.'/app/Example.php', '<?php class Example { /* changed */ }');

        $build = $this->buildAndroid();

        $this->assertSame(['composer dump-autoload --optimize --classmap-authoritative'], $build['ran']);
        $this->assertStringContainsString('changed', $this->androidZipEntry('app/Example.php'));
    }

    public function test_android_view_change_skips_the_autoloader_but_ships_the_view(): void
    {
        $this->buildAndroid();

        File::put($this->root.'/resources/views/welcome.blade.php', '<h1>Changed welcome page</h1>');

        $build = $this->buildAndroid();

        $this->assertSame([], $build['ran']);
        $this->assertSame('<h1>Changed welcome page</h1>', $this->androidZipEntry('resources/views/welcome.blade.php'));
    }

    public function test_android_deleted_files_disappear_from_the_next_bundle(): void
    {
        $this->buildAndroid();

        File::delete($this->root.'/resources/views/welcome.blade.php');
        $this->buildAndroid();

        $zip = $this->androidZip();
        $this->assertFalse($zip->statName('resources/views/welcome.blade.php'));
        $zip->close();
    }

    public function test_android_lock_file_change_reruns_composer(): void
    {
        $this->buildAndroid();

        File::put($this->root.'/composer.lock', '{"packages": [{"name": "acme/pkg", "version": "1.0.1"}]}');

        $this->assertSame([
            'composer install --no-interaction',
            'composer dump-autoload --optimize --classmap-authoritative',
        ], $this->buildAndroid()['ran']);
    }

    public function test_android_vendor_change_reruns_composer_and_ships_the_new_file(): void
    {
        $this->buildAndroid();

        // What a path repository or a hand edit looks like: vendor changes,
        // composer.lock doesn't.
        File::put($this->root.'/vendor/acme/pkg/src/Pkg.php', '<?php // new vendor code');

        $build = $this->buildAndroid();

        $this->assertContains('composer install --no-interaction', $build['ran']);
        $this->assertSame('<?php // new vendor code', $this->androidZipEntry('vendor/acme/pkg/src/Pkg.php'));
    }

    public function test_android_build_type_change_reruns_everything(): void
    {
        $this->buildAndroid();

        $build = $this->buildAndroid(excludeDev: true);

        $this->assertSame([
            'composer install --no-dev --no-interaction',
            'composer dump-autoload --optimize --classmap-authoritative',
        ], $build['ran']);
        $this->assertContains('Copying Laravel source', $build['tasks']);
    }

    public function test_android_fresh_reruns_everything(): void
    {
        $this->buildAndroid();

        $build = $this->buildAndroid(reuse: false);

        $this->assertCount(2, $build['ran']);
        $this->assertContains('Copying Laravel source', $build['tasks']);
    }

    public function test_android_cleared_state_reruns_everything(): void
    {
        $this->buildAndroid();

        BuildState::clear($this->root.'/nativephp/android');

        $this->assertCount(2, $this->buildAndroid()['ran']);
    }

    public function test_android_custom_composer_scripts_always_rerun_composer(): void
    {
        $composerJson = json_decode(File::get($this->root.'/composer.json'), true);
        $composerJson['scripts']['post-autoload-dump'][] = '@php artisan filament:upgrade';
        File::put($this->root.'/composer.json', json_encode($composerJson));

        $this->buildAndroid();

        $this->assertContains('composer install --no-interaction', $this->buildAndroid()['ran']);
    }

    public function test_android_failed_composer_install_is_not_remembered(): void
    {
        $this->buildAndroid();
        File::put($this->root.'/composer.lock', '{"packages": [{"name": "acme/pkg", "version": "1.0.1"}]}');

        // Simulate the build dying inside composer install: the step was
        // forgotten before it ran and never recorded.
        $state = BuildState::for($this->root.'/nativephp/android', 'dev');
        $state->forget('composer');

        $this->assertContains('composer install --no-interaction', $this->buildAndroid()['ran']);
    }

    // iOS

    public function test_ios_second_build_skips_composer_and_keeps_the_staged_app(): void
    {
        $first = $this->buildIos();

        $this->assertSame(['composer install'], $first['ran']);

        $second = $this->buildIos();

        $this->assertSame([], $second['ran']);
        $this->assertContains('Installing Composer dependencies: skipped (unchanged)', $second['skipped']);
        $this->assertContains('Updating Composer autoloader: skipped (unchanged)', $second['skipped']);
        $this->assertContains('Copying Laravel app (changed files only)', $second['tasks']);

        $zip = $this->iosZip();
        $this->assertNotFalse($zip->statName('app/Example.php'));
        $this->assertNotFalse($zip->statName('vendor/acme/pkg/src/Pkg.php'));
        $this->assertFalse($zip->statName('composer.lock'));
        $zip->close();

        // ASSET_URL is appended once per build, not once per build ever.
        $this->assertSame(1, substr_count(File::get($this->root.'/nativephp/ios/laravel/.env'), 'ASSET_URL'));
    }

    public function test_ios_app_class_change_refreshes_the_autoloader_without_scripts(): void
    {
        $this->buildIos();

        File::put($this->root.'/app/NewThing.php', '<?php class NewThing {}');

        $this->assertSame(['composer dump-autoload --no-scripts'], $this->buildIos()['ran']);
    }

    public function test_ios_release_build_uses_no_dev_and_its_own_state(): void
    {
        $this->buildIos();

        $this->assertSame(['composer install --no-dev'], $this->buildIos(['--release' => true])['ran']);
        $this->assertSame([], $this->buildIos(['--release' => true])['ran']);

        File::put($this->root.'/app/NewThing.php', '<?php class NewThing {}');

        $this->assertSame(['composer dump-autoload --no-scripts --no-dev'], $this->buildIos(['--release' => true])['ran']);
    }

    public function test_ios_fresh_and_xcode_builds_rerun_composer(): void
    {
        $this->buildIos();

        $this->assertSame(['composer install'], $this->buildIos(['--fresh' => true])['ran']);

        putenv('NATIVEPHP_XCODE_BUILD=1');
        $this->assertSame(['composer install'], $this->buildIos()['ran']);
    }

    public function test_ios_cocoapods_and_swift_packages_are_skipped_when_unchanged(): void
    {
        $this->makeIosProject();

        $first = $this->runIosSteps();
        $this->assertSame(['pod install', 'xcodebuild -resolvePackageDependencies -project NativePHP.xcodeproj'], $first['ran']);

        $second = $this->runIosSteps();
        $this->assertSame([], $second['ran']);
        $this->assertContains('Installing CocoaPods dependencies: skipped (unchanged)', $second['skipped']);
        $this->assertContains('Resolving Swift Package dependencies: skipped (unchanged)', $second['skipped']);

        // A plugin adds a pod: both steps see the change.
        File::put($this->root.'/nativephp/ios/Podfile', File::get($this->root.'/nativephp/ios/Podfile')."  pod 'Mapbox'\n");
        File::append($this->root.'/nativephp/ios/NativePHP.xcodeproj/project.pbxproj', "\n/* plugin */");

        $third = $this->runIosSteps();
        $this->assertSame(['pod install', 'xcodebuild -resolvePackageDependencies -project NativePHP.xcodeproj'], $third['ran']);

        $this->assertSame(['pod install', 'xcodebuild -resolvePackageDependencies -project NativePHP.xcodeproj'], $this->runIosSteps(['--fresh' => true])['ran']);
    }

    public function test_ios_failed_swift_package_resolution_is_not_remembered(): void
    {
        $this->makeIosProject();
        $this->runIosSteps();

        File::append($this->root.'/nativephp/ios/NativePHP.xcodeproj/project.pbxproj', "\n/* changed */");

        Process::fake(["'xcodebuild' *" => Process::result(exitCode: 1), '*' => Process::result()]);
        $this->runIosSteps();

        $this->ran = [];
        Process::fake(["'xcodebuild' *" => function ($process) {
            $this->ran[] = implode(' ', $process->command);

            return Process::result();
        }, '*' => Process::result()]);

        $this->assertSame(['xcodebuild -resolvePackageDependencies -project NativePHP.xcodeproj'], $this->runIosSteps()['ran']);
    }

    // Helpers

    /**
     * @return array{ran: array<int, string>, tasks: array<int, string>, skipped: array<int, string>}
     */
    protected function buildAndroid(bool $excludeDev = false, bool $reuse = true): array
    {
        $this->ran = [];
        $builder = new SkipUnchangedAndroidBuilder;
        $builder->testPrepareLaravelBundle($excludeDev, $reuse);

        return ['ran' => $this->ran, 'tasks' => $builder->components->tasks, 'skipped' => $builder->components->details];
    }

    /**
     * @return array{ran: array<int, string>, tasks: array<int, string>, skipped: array<int, string>}
     */
    protected function buildIos(array $options = []): array
    {
        $this->ran = [];
        $command = $this->iosCommand($options);
        $command->bundle();

        return ['ran' => $this->ran, 'tasks' => $command->tasks(), 'skipped' => $command->skipped()];
    }

    /**
     * @return array{ran: array<int, string>, tasks: array<int, string>, skipped: array<int, string>}
     */
    protected function runIosSteps(array $options = []): array
    {
        $this->ran = [];
        $command = $this->iosCommand($options);
        $command->podsAndPackages();

        return ['ran' => $this->ran, 'tasks' => $command->tasks(), 'skipped' => $command->skipped()];
    }

    protected function iosCommand(array $options): SkipUnchangedIosProbe
    {
        File::ensureDirectoryExists($this->root.'/nativephp/ios/NativePHP');

        $command = new SkipUnchangedIosProbe;
        $command->setLaravel($this->app);

        $input = new ArrayInput($options, $command->getDefinition());
        $command->prime($input, new OutputStyle($input, new BufferedOutput));

        return $command;
    }

    protected function androidZip(): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->root.'/nativephp/android/app/src/main/assets/laravel_bundle.zip'));

        return $zip;
    }

    protected function androidZipEntry(string $name): string
    {
        $zip = $this->androidZip();
        $contents = $zip->getFromName($name);
        $zip->close();

        $this->assertIsString($contents, "{$name} is in the bundle");

        return $contents;
    }

    protected function iosZip(): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->root.'/nativephp/ios/NativePHP/app.zip'));

        return $zip;
    }

    protected function makeLaravelApp(): void
    {
        File::ensureDirectoryExists($this->root.'/app');
        File::ensureDirectoryExists($this->root.'/resources/views');
        File::ensureDirectoryExists($this->root.'/bootstrap/cache');
        File::ensureDirectoryExists($this->root.'/vendor/acme/pkg/src');
        File::ensureDirectoryExists($this->root.'/vendor/nativephp/mobile/bootstrap/android');
        File::ensureDirectoryExists($this->root.'/nativephp/android/app/src/main/assets');

        File::put($this->root.'/composer.json', json_encode([
            'name' => 'acme/app',
            'require' => ['acme/pkg' => '^1.0'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'scripts' => [
                'post-autoload-dump' => [
                    'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                    '@php artisan package:discover --ansi',
                ],
            ],
        ]));
        File::put($this->root.'/composer.lock', '{"packages": [{"name": "acme/pkg", "version": "1.0.0"}]}');
        File::put($this->root.'/artisan', '#!/usr/bin/env php');
        File::put($this->root.'/.env', "APP_NAME=Test\n");
        File::put($this->root.'/app/Example.php', '<?php class Example {}');
        File::put($this->root.'/app/release-fixture.bin', random_bytes(2048));
        File::put($this->root.'/resources/views/welcome.blade.php', '<h1>Welcome</h1>');
        File::put($this->root.'/vendor/autoload.php', '<?php');
        File::put($this->root.'/vendor/acme/pkg/src/Pkg.php', '<?php');
        File::put($this->root.'/vendor/nativephp/mobile/bootstrap/android/artisan.php', '<?php // artisan');
    }

    protected function makeIosProject(): void
    {
        $ios = $this->root.'/nativephp/ios';

        File::ensureDirectoryExists($ios.'/Pods/Pods.xcodeproj');
        File::ensureDirectoryExists($ios.'/NativePHP.xcodeproj/project.xcworkspace/xcshareddata/swiftpm');
        File::ensureDirectoryExists($ios.'/NativePHP.xcworkspace');

        File::put($ios.'/Podfile', "platform :ios, '18.2'\ntarget 'NativePHP' do\n");
        File::put($ios.'/Podfile.lock', "PODS:\n  - Alamofire (5.9.0)\n");
        File::copy($ios.'/Podfile.lock', $ios.'/Pods/Manifest.lock');
        File::put($ios.'/Pods/Pods.xcodeproj/project.pbxproj', '// pods');
        File::put($ios.'/NativePHP.xcworkspace/contents.xcworkspacedata', '<Workspace/>');
        File::put($ios.'/NativePHP.xcodeproj/project.pbxproj', '/* XCRemoteSwiftPackageReference "Thing" */');
        File::put($ios.'/NativePHP.xcodeproj/project.xcworkspace/xcshareddata/swiftpm/Package.resolved', '{"pins": []}');
    }
}

class SkipUnchangedAndroidBuilder
{
    use PreparesBuild {
        prepareLaravelBundle as public testPrepareLaravelBundle;
    }

    public object $components;

    public function __construct()
    {
        $this->components = new class
        {
            /** @var array<int, string> */
            public array $tasks = [];

            /** @var array<int, string> */
            public array $details = [];

            public function task(string $title, callable $callback): mixed
            {
                $this->tasks[] = $title;

                return $callback();
            }

            public function twoColumnDetail(string $first, ?string $second = null): void
            {
                $this->details[] = $first.': '.strip_tags((string) $second);
            }

            public function warn(...$args): void {}
        };
    }

    protected function logToFile(string $message): void {}

    protected function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

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

class SkipUnchangedIosProbe extends BuildIosAppCommand
{
    protected RecordingComponents $recording;

    public function prime(InputInterface $input, OutputStyle $output): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->recording = new RecordingComponents($output);
        $this->components = $this->recording;

        $this->basePath = base_path('nativephp/ios');

        foreach ([
            'logPath' => base_path('nativephp/ios-build.log'),
            'containerPath' => $this->basePath.'/NativePHP/',
            'appPath' => $this->basePath.'/laravel/',
            'xcodeProjectPath' => $this->basePath.'/NativePHP.xcodeproj',
            'verbose' => false,
        ] as $property => $value) {
            $reflection = new \ReflectionProperty(BuildIosAppCommand::class, $property);
            $reflection->setValue($this, $value);
        }
    }

    public function bundle(): void
    {
        $this->bundleLaravelApp();
    }

    public function podsAndPackages(): void
    {
        foreach (['installCocoaPods', 'resolveSwiftPackages'] as $method) {
            (new \ReflectionMethod(BuildIosAppCommand::class, $method))->invoke($this);
        }
    }

    /** @return array<int, string> */
    public function tasks(): array
    {
        return $this->recording->tasks;
    }

    /** @return array<int, string> */
    public function skipped(): array
    {
        return $this->recording->details;
    }
}

class RecordingComponents extends Factory
{
    /** @var array<int, string> */
    public array $tasks = [];

    /** @var array<int, string> */
    public array $details = [];

    public function task($description, $task = null, $verbosity = null)
    {
        $this->tasks[] = $description;

        return parent::task($description, $task);
    }

    public function twoColumnDetail($first, $second = null, $verbosity = null)
    {
        $this->details[] = $first.': '.strip_tags((string) $second);
    }
}
