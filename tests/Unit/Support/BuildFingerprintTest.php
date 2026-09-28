<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\File;
use Native\Mobile\Support\BuildFingerprint;
use Native\Mobile\Support\BundleFileManager;
use Tests\TestCase;

class BuildFingerprintTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = realpath(sys_get_temp_dir()).'/nativephp_fingerprint_test_'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    // Composer

    public function test_composer_fingerprint_is_stable_when_nothing_changes(): void
    {
        $this->makeComposerProject();

        $this->assertNotNull(BuildFingerprint::composer($this->root, []));
        $this->assertSame(
            BuildFingerprint::composer($this->root, []),
            BuildFingerprint::composer($this->root, []),
        );
    }

    public function test_composer_fingerprint_changes_with_the_lock_file(): void
    {
        $this->makeComposerProject();
        $before = BuildFingerprint::composer($this->root, []);

        File::put($this->root.'/composer.lock', '{"packages": [{"name": "acme/pkg", "version": "2.0.0"}]}');

        $this->assertNotSame($before, BuildFingerprint::composer($this->root, []));
    }

    public function test_composer_fingerprint_changes_with_composer_json(): void
    {
        $this->makeComposerProject();
        $before = BuildFingerprint::composer($this->root, []);

        $this->writeComposerJson(['require' => ['acme/pkg' => '^2.0']]);

        $this->assertNotSame($before, BuildFingerprint::composer($this->root, []));
    }

    public function test_composer_fingerprint_changes_with_the_flags(): void
    {
        $this->makeComposerProject();

        $this->assertNotSame(
            BuildFingerprint::composer($this->root, []),
            BuildFingerprint::composer($this->root, ['--no-dev']),
        );
    }

    public function test_composer_fingerprint_changes_when_a_vendor_file_changes_without_the_lock(): void
    {
        // Path repositories and hand-edited vendor files change the bundle
        // without composer.lock noticing.
        $this->makeComposerProject();
        $before = BuildFingerprint::composer($this->root, []);

        File::put($this->root.'/vendor/acme/pkg/src/Pkg.php', '<?php // edited by hand');

        $this->assertNotSame($before, BuildFingerprint::composer($this->root, []));
    }

    public function test_composer_fingerprint_changes_when_a_vendor_file_is_added_or_removed(): void
    {
        $this->makeComposerProject();
        $before = BuildFingerprint::composer($this->root, []);

        File::put($this->root.'/vendor/acme/pkg/src/Extra.php', '<?php');
        $added = BuildFingerprint::composer($this->root, []);

        File::delete($this->root.'/vendor/acme/pkg/src/Extra.php');
        File::delete($this->root.'/vendor/acme/pkg/src/Pkg.php');
        $removed = BuildFingerprint::composer($this->root, []);

        $this->assertNotSame($before, $added);
        $this->assertNotSame($before, $removed);
        $this->assertNotSame($added, $removed);
    }

    public function test_composer_fingerprint_follows_path_repository_symlinks(): void
    {
        $this->makeComposerProject();

        $package = $this->root.'/packages/local';
        File::ensureDirectoryExists($package.'/src');
        File::put($package.'/src/Local.php', '<?php');
        File::ensureDirectoryExists($this->root.'/vendor/acme');
        symlink($package, $this->root.'/vendor/acme/local');

        $before = BuildFingerprint::composer($this->root, []);

        File::put($package.'/src/Local.php', '<?php // changed in the path repository');

        $this->assertNotSame($before, BuildFingerprint::composer($this->root, []));
    }

    public function test_composer_fingerprint_ignores_paths_the_bundle_never_copies(): void
    {
        $this->makeComposerProject();

        File::ensureDirectoryExists($this->root.'/vendor/acme/pkg/vendor/nested');
        File::ensureDirectoryExists($this->root.'/vendor/acme/pkg/tests');
        File::ensureDirectoryExists($this->root.'/vendor/acme/pkg/.git');

        $before = BuildFingerprint::composer($this->root, []);

        File::put($this->root.'/vendor/acme/pkg/vendor/nested/File.php', '<?php');
        File::put($this->root.'/vendor/acme/pkg/tests/PkgTest.php', '<?php');
        File::put($this->root.'/vendor/acme/pkg/.git/HEAD', 'ref: refs/heads/main');

        $this->assertSame($before, BuildFingerprint::composer($this->root, []));
    }

    public function test_composer_fingerprint_survives_a_symlink_cycle(): void
    {
        $this->makeComposerProject();
        symlink($this->root.'/vendor/acme', $this->root.'/vendor/acme/pkg/src/loop');

        $this->assertNotNull(BuildFingerprint::composer($this->root, []));
    }

    public function test_composer_install_is_never_skipped_without_a_lock_file_or_vendor(): void
    {
        $this->makeComposerProject();
        File::delete($this->root.'/composer.lock');

        $this->assertNull(BuildFingerprint::composer($this->root, []));

        $this->makeComposerProject();
        File::deleteDirectory($this->root.'/vendor');

        $this->assertNull(BuildFingerprint::composer($this->root, []));
    }

    public function test_composer_install_is_never_skipped_when_it_runs_custom_scripts(): void
    {
        $this->makeComposerProject();
        $this->writeComposerJson(['scripts' => [
            'post-autoload-dump' => [
                'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                '@php artisan package:discover --ansi',
                '@php artisan filament:upgrade',
            ],
        ]]);

        $this->assertNull(BuildFingerprint::composer($this->root, []));
    }

    public function test_laravel_default_scripts_do_not_prevent_skipping(): void
    {
        $this->assertFalse(BuildFingerprint::hasCustomInstallScripts(['scripts' => [
            'post-autoload-dump' => [
                'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                '@php artisan package:discover --ansi',
            ],
            'post-update-cmd' => ['@php artisan vendor:publish --tag=laravel-assets --ansi --force'],
            'post-create-project-cmd' => ['@php artisan key:generate --ansi'],
        ]]));

        $this->assertTrue(BuildFingerprint::hasCustomInstallScripts(['scripts' => [
            'post-install-cmd' => '@php artisan something',
        ]]));
    }

    // Autoload

    public function test_autoload_paths_come_from_composer_json(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => ['App\\' => 'app/', 'Database\\Seeders\\' => ['database/seeders/']],
                'files' => ['./app/helpers.php'],
                'classmap' => ['legacy'],
            ],
            'autoload-dev' => [
                'psr-4' => ['Tests\\' => 'tests/'],
            ],
        ];

        $this->assertSame(
            ['app', 'database/seeders', 'legacy', 'app/helpers.php'],
            BuildFingerprint::autoloadPaths($composerJson, dev: false),
        );

        $this->assertContains('tests', BuildFingerprint::autoloadPaths($composerJson, dev: true));
    }

    public function test_a_root_or_glob_autoload_path_covers_everything(): void
    {
        $this->assertSame([''], BuildFingerprint::autoloadPaths(['autoload' => ['psr-4' => ['App\\' => '']]], false));
        $this->assertSame([''], BuildFingerprint::autoloadPaths(['autoload' => ['classmap' => ['modules/*/src']]], false));
    }

    public function test_touches_matches_changes_under_autoload_paths(): void
    {
        $paths = ['app', 'database/seeders'];

        $this->assertTrue(BuildFingerprint::touches(['app/Models/User.php'], $paths));
        $this->assertTrue(BuildFingerprint::touches(['database/seeders/'], $paths));
        // A whole directory above an autoload path was added or removed.
        $this->assertTrue(BuildFingerprint::touches(['database/'], $paths));

        $this->assertFalse(BuildFingerprint::touches([], $paths));
        $this->assertFalse(BuildFingerprint::touches(['resources/views/welcome.blade.php', 'routes/web.php', '.env'], $paths));
        $this->assertFalse(BuildFingerprint::touches(['application.php'], $paths));
    }

    public function test_unknown_changes_touch_everything(): void
    {
        $this->assertTrue(BuildFingerprint::touches(null, ['app']));
        $this->assertTrue(BuildFingerprint::touches([''], ['app']));
    }

    // rsync output

    public function test_itemized_changes_from_openrsync_are_parsed(): void
    {
        $output = implode("\n", [
            '*deleting keep.lock',
            '>f+++++++ new file.php',
            '>f.s..... app/Foo.php',
            'cd+++++++ app/New/',
            '.d..t.... app/',
            '',
        ]);

        $this->assertSame(
            ['keep.lock', 'new file.php', 'app/Foo.php', 'app/New/'],
            BundleFileManager::parseItemizedChanges($output),
        );
    }

    public function test_itemized_changes_from_gnu_rsync_are_parsed(): void
    {
        $output = implode("\n", [
            '*deleting   app/Old.php',
            '>f+++++++++ app/New.php',
            '>f.st...... app/Foo.php',
            '.d..t...... app/',
        ]);

        $this->assertSame(
            ['app/Old.php', 'app/New.php', 'app/Foo.php'],
            BundleFileManager::parseItemizedChanges($output),
        );
    }

    public function test_unrecognised_rsync_output_counts_as_an_unknown_change(): void
    {
        $this->assertSame([''], BundleFileManager::parseItemizedChanges('something unexpected'));
    }

    // CocoaPods and Swift packages

    public function test_cocoapods_fingerprint_is_stable_and_follows_its_inputs(): void
    {
        $this->makeIosProject();
        $before = BuildFingerprint::cocoaPods($this->root);

        $this->assertNotNull($before);
        $this->assertSame($before, BuildFingerprint::cocoaPods($this->root));

        foreach (['Podfile', 'Podfile.lock', 'NativePHP.xcodeproj/project.pbxproj'] as $input) {
            $this->makeIosProject();
            File::append($this->root.'/'.$input, "\n# changed");

            if ($input === 'Podfile.lock') {
                File::copy($this->root.'/Podfile.lock', $this->root.'/Pods/Manifest.lock');
            }

            $this->assertNotSame($before, BuildFingerprint::cocoaPods($this->root), "{$input} changed");
        }
    }

    public function test_cocoapods_is_never_skipped_when_the_sandbox_is_out_of_sync(): void
    {
        $this->makeIosProject();
        File::put($this->root.'/Pods/Manifest.lock', 'PODS: [different]');

        $this->assertNull(BuildFingerprint::cocoaPods($this->root));
    }

    public function test_cocoapods_is_never_skipped_without_a_previous_install(): void
    {
        foreach (['Podfile.lock', 'Pods/Manifest.lock', 'Pods/Pods.xcodeproj/project.pbxproj', 'NativePHP.xcworkspace/contents.xcworkspacedata'] as $missing) {
            $this->makeIosProject();
            File::delete($this->root.'/'.$missing);

            $this->assertNull(BuildFingerprint::cocoaPods($this->root), "{$missing} missing");
        }
    }

    public function test_cocoapods_is_never_skipped_with_local_pods(): void
    {
        $this->makeIosProject();
        File::append($this->root.'/Podfile', "\n  pod 'Local', :path => '../local'\n");

        $this->assertNull(BuildFingerprint::cocoaPods($this->root));
    }

    public function test_swift_package_fingerprint_needs_a_resolved_file_and_follows_the_project(): void
    {
        $this->makeIosProject();

        $this->assertNull(BuildFingerprint::swiftPackages($this->root));

        $resolved = $this->root.'/NativePHP.xcodeproj/project.xcworkspace/xcshareddata/swiftpm/Package.resolved';
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, '{"pins": []}');

        $before = BuildFingerprint::swiftPackages($this->root);
        $this->assertNotNull($before);
        $this->assertSame($before, BuildFingerprint::swiftPackages($this->root));

        File::append($this->root.'/NativePHP.xcodeproj/project.pbxproj', "\n/* XCRemoteSwiftPackageReference */");
        $this->assertNotSame($before, BuildFingerprint::swiftPackages($this->root));

        $afterProject = BuildFingerprint::swiftPackages($this->root);
        File::put($resolved, '{"pins": [{"identity": "x"}]}');
        $this->assertNotSame($afterProject, BuildFingerprint::swiftPackages($this->root));
    }

    // Helpers

    protected function makeComposerProject(): void
    {
        $this->writeComposerJson([]);
        File::put($this->root.'/composer.lock', '{"packages": [{"name": "acme/pkg", "version": "1.0.0"}]}');
        File::ensureDirectoryExists($this->root.'/vendor/acme/pkg/src');
        File::put($this->root.'/vendor/acme/pkg/src/Pkg.php', '<?php');
        File::put($this->root.'/vendor/autoload.php', '<?php');
    }

    protected function writeComposerJson(array $overrides): void
    {
        File::put($this->root.'/composer.json', json_encode(array_merge([
            'name' => 'acme/app',
            'require' => ['acme/pkg' => '^1.0'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'scripts' => [
                'post-autoload-dump' => [
                    'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                    '@php artisan package:discover --ansi',
                ],
            ],
        ], $overrides)));
    }

    protected function makeIosProject(): void
    {
        File::put($this->root.'/Podfile', "platform :ios, '18.2'\ntarget 'NativePHP' do\n  pod 'Alamofire', '~> 5.0'\nend\n");
        File::put($this->root.'/Podfile.lock', "PODS:\n  - Alamofire (5.9.0)\n");
        File::ensureDirectoryExists($this->root.'/Pods/Pods.xcodeproj');
        File::copy($this->root.'/Podfile.lock', $this->root.'/Pods/Manifest.lock');
        File::put($this->root.'/Pods/Pods.xcodeproj/project.pbxproj', '// pods project');
        File::ensureDirectoryExists($this->root.'/NativePHP.xcodeproj');
        File::put($this->root.'/NativePHP.xcodeproj/project.pbxproj', '// app project');
        File::ensureDirectoryExists($this->root.'/NativePHP.xcworkspace');
        File::put($this->root.'/NativePHP.xcworkspace/contents.xcworkspacedata', '<Workspace/>');
    }
}
