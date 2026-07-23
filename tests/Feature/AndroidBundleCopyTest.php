<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Support\BundleFileManager;
use Native\Mobile\Traits\PreparesBuild;
use Tests\TestCase;

class AndroidBundleCopyTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_android_bundle_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_android_bundle_delegates_exclusions_to_bundle_file_manager(): void
    {
        // Regression guard. The Android bundle prep must route its copy and
        // cleanup through BundleFileManager so both platforms share one
        // exclusion set and config('nativephp.cleanup_exclude_files')
        // is honoured. A previous inline exclude list silently kept
        // vendor bloat in and diverged from the iOS bundle.
        $source = file_get_contents(
            (new \ReflectionClass(PreparesBuild::class))->getFileName()
        );

        $this->assertStringContainsString('BundleFileManager::copy(', $source);
        $this->assertStringContainsString('BundleFileManager::removeUnnecessaryFiles(', $source);
        $this->assertStringNotContainsString('platformOptimizedCopy', $source);
    }

    public function test_robocopy_registers_bare_patterns_as_any_depth_dir_and_file(): void
    {
        $cmd = $this->runFakedRobocopy();

        // Unanchored names must keep rsync's match-anywhere semantics on
        // Windows, which robocopy only provides for bare (relative) names.
        $this->assertStringContainsString('/XD "node_modules" /XF "node_modules"', $cmd);
        $this->assertStringContainsString('/XD ".git" /XF ".git"', $cmd);
        $this->assertStringContainsString('/XD ".DS_Store" /XF ".DS_Store"', $cmd);
    }

    public function test_robocopy_skips_multi_level_vendor_wildcards(): void
    {
        $cmd = $this->runFakedRobocopy();

        // vendor/**/*.md style patterns have no /XD or /XF equivalent and
        // would otherwise be passed as literal never-matching paths.
        $this->assertStringNotContainsString('**', $cmd);
    }

    public function test_robocopy_expands_single_level_wildcards_via_glob(): void
    {
        $cmd = $this->runFakedRobocopy([
            'vendor' => [
                'acme' => ['pkg' => ['vendor' => ['dep.php' => '<?php']]],
                'other' => ['pkg2' => ['vendor' => ['dep.php' => '<?php']]],
            ],
        ]);

        // Nested vendor dirs (composer path-repo junctions on Windows) are
        // expanded at PHP level because /XD cannot express wildcards.
        $source = $this->testProjectPath.'/app-source';
        $this->assertStringContainsString('/XD "'.str_replace('/', '\\', $source.'/vendor/acme/pkg/vendor').'"', $cmd);
        $this->assertStringContainsString('/XD "'.str_replace('/', '\\', $source.'/vendor/other/pkg2/vendor').'"', $cmd);
    }

    public function test_robocopy_uses_xf_for_anchored_files_and_xd_for_dirs(): void
    {
        $cmd = $this->runFakedRobocopy([
            '.env.example' => 'APP_NAME=',
            'nativephp' => ['android' => ['build.gradle' => '']],
        ]);

        // /XD only excludes directories — anchored files need /XF or
        // they get mirrored into the bundle regardless.
        $source = $this->testProjectPath.'/app-source';
        $this->assertStringContainsString('/XF "'.str_replace('/', '\\', $source.'/.env.example').'"', $cmd);
        $this->assertStringContainsString('/XD "'.str_replace('/', '\\', $source.'/nativephp').'"', $cmd);
    }

    public function test_robocopy_normalizes_path_separators_in_source_and_destination(): void
    {
        $cmd = $this->runFakedRobocopy();

        $source = str_replace('/', '\\', $this->testProjectPath.'/app-source');
        $destination = str_replace('/', '\\', $this->testProjectPath.'/bundle');

        $this->assertStringContainsString("robocopy \"{$source}\" \"{$destination}\"", $cmd);
    }

    public function test_robocopy_includes_vendor_export_ignore_patterns(): void
    {
        $cmd = $this->runFakedRobocopy([
            'vendor' => ['acme' => ['plugin' => [
                '.gitattributes' => "/tests export-ignore\n/docs export-ignore\n",
                'tests' => ['PluginTest.php' => '<?php'],
                'docs' => ['guide.md' => '# docs'],
            ]]],
        ]);

        // Parity with the rsync backend: .gitattributes export-ignore
        // entries become per-package anchored exclusions on Windows too.
        $source = $this->testProjectPath.'/app-source';
        $this->assertStringContainsString('/XD "'.str_replace('/', '\\', $source.'/vendor/acme/plugin/tests').'"', $cmd);
        $this->assertStringContainsString('/XD "'.str_replace('/', '\\', $source.'/vendor/acme/plugin/docs').'"', $cmd);
    }

    public function test_robocopy_anchors_config_excludes_under_source(): void
    {
        $cmd = $this->runFakedRobocopy(
            ['custom' => ['cache' => ['data.bin' => 'cached']]],
            configPaths: ['custom/cache'],
        );

        $source = $this->testProjectPath.'/app-source';
        $this->assertStringContainsString('/XD "'.str_replace('/', '\\', $source.'/custom/cache').'"', $cmd);
    }

    public function test_robocopy_prunes_vendor_patterns_from_destination(): void
    {
        Process::fake([
            'robocopy*' => Process::result(output: '', exitCode: 1),
        ]);

        $source = $this->testProjectPath.'/app-source';
        $this->createDirectoryStructure($source.'/', ['app' => ['Models' => ['User.php' => '<?php']]]);

        // Stage the destination as robocopy leaves it: vendor docs and
        // metadata still present, since /XD and /XF cannot express the
        // multi-level vendor/** wildcards rsync filters natively.
        $destination = $this->testProjectPath.'/bundle';
        $this->createDirectoryStructure($destination.'/', [
            'resources' => ['keep.md' => '# keep'],
            'vendor' => [
                'autoload.php' => '<?php',
                'stray.md' => '# depth zero, rsync would keep it',
                'acme' => ['pkg' => [
                    'README.md' => '# strip',
                    'LICENSE' => 'MIT',
                    'phpstan.neon' => 'includes: []',
                    'docs' => ['guide.md' => '# strip'],
                    'src' => ['Pkg.php' => '<?php', 'deep' => ['notes.md' => '# strip']],
                ]],
            ],
        ]);

        (new \ReflectionMethod(BundleFileManager::class, 'copyWithRobocopy'))
            ->invoke(null, $source, $destination, []);

        // Pruned at any depth inside vendor packages.
        $this->assertFileDoesNotExist($destination.'/vendor/acme/pkg/README.md');
        $this->assertFileDoesNotExist($destination.'/vendor/acme/pkg/LICENSE');
        $this->assertFileDoesNotExist($destination.'/vendor/acme/pkg/phpstan.neon');
        $this->assertDirectoryDoesNotExist($destination.'/vendor/acme/pkg/docs');
        $this->assertFileDoesNotExist($destination.'/vendor/acme/pkg/src/deep/notes.md');

        // Untouched: runtime vendor code, vendor root files (rsync's
        // vendor/**/ form needs one directory level), and app files.
        $this->assertFileExists($destination.'/vendor/acme/pkg/src/Pkg.php');
        $this->assertFileExists($destination.'/vendor/autoload.php');
        $this->assertFileExists($destination.'/vendor/stray.md');
        $this->assertFileExists($destination.'/resources/keep.md');
    }

    public function test_robocopy_throws_when_exit_code_signals_failure(): void
    {
        // Robocopy exit codes below 8 are success variants; 8+ mean
        // at least one copy failure and must abort the build.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('robocopy exit code 8');

        $this->runFakedRobocopy(exitCode: 8);
    }

    // Helpers

    /**
     * Stage a source tree, run the private robocopy backend against a
     * faked process and hand back the command line it would execute.
     */
    protected function runFakedRobocopy(array $structure = [], array $configPaths = [], int $exitCode = 1): string
    {
        Process::fake([
            'robocopy*' => Process::result(output: '', exitCode: $exitCode),
        ]);

        $source = $this->testProjectPath.'/app-source';
        $this->createDirectoryStructure($source.'/', $structure ?: ['app' => ['Models' => ['User.php' => '<?php']]]);

        $destination = $this->testProjectPath.'/bundle';

        (new \ReflectionMethod(BundleFileManager::class, 'copyWithRobocopy'))
            ->invoke(null, $source, $destination, $configPaths);

        $command = null;
        Process::assertRan(function ($process) use (&$command) {
            $command = $process->command;

            return true;
        });

        return $command;
    }
}
