<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Native\Mobile\Concerns\RunsAndroid;
use Native\Mobile\Plugins\Compilers\AndroidPluginCompiler;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\TestCase;

/**
 * The app module applies com.google.gms.google-services whenever a
 * google-services.json is present. Nothing declared it, so adding that file —
 * which every Firebase feature requires — stopped the build with
 * "Plugin with id 'com.google.gms.google-services' not found".
 */
class GoogleServicesClasspathTest extends TestCase
{
    use RunsAndroid {
        updateFirebaseConfiguration as public runUpdateFirebaseConfiguration;
    }

    protected string $testProjectPath;

    /** @var array<int, string> */
    protected array $warnings = [];

    protected const ROOT_BUILD_FILE = <<<'KTS'
        // Top-level build file where you can add configuration options common to all sub-projects/modules.
        plugins {
            alias(libs.plugins.android.application) apply false
            alias(libs.plugins.kotlin.android) apply false
            alias(libs.plugins.kotlin.compose) apply false
        }

        KTS;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_gms_test_'.uniqid();
        app()->setBasePath($this->testProjectPath);

        $warnings = &$this->warnings;
        $this->components = new class($warnings)
        {
            public function __construct(protected array &$warnings) {}

            public function task(string $title, callable $callback)
            {
                $callback();
            }

            public function twoColumnDetail(...$args) {}

            public function warn($message = '')
            {
                $this->warnings[] = (string) $message;
            }
        };

        File::ensureDirectoryExists($this->testProjectPath.'/nativephp/android/app');
        File::put($this->testProjectPath.'/nativephp/android/build.gradle.kts', self::ROOT_BUILD_FILE);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    protected function addGoogleServicesJson(): void
    {
        File::put($this->testProjectPath.'/google-services.json', '{"project_id": "test"}');
    }

    protected function rootBuildFile(): string
    {
        return File::get($this->testProjectPath.'/nativephp/android/build.gradle.kts');
    }

    /**
     * @test
     *
     * The defect itself. A project with a google-services.json builds, because
     * the plugin the app module applies is on the classpath.
     */
    public function it_declares_the_plugin_when_the_project_has_a_google_services_json(): void
    {
        $this->addGoogleServicesJson();

        $this->runUpdateFirebaseConfiguration();

        $this->assertStringContainsString(
            'id("com.google.gms.google-services") version "4.4.3" apply false',
            $this->rootBuildFile()
        );
    }

    /**
     * @test
     *
     * The declaration goes inside the plugins {} block. Anywhere else is a
     * Gradle syntax error, so "the string is in the file" is not enough.
     */
    public function it_declares_the_plugin_inside_the_plugins_block(): void
    {
        $this->addGoogleServicesJson();

        $this->runUpdateFirebaseConfiguration();

        $this->assertMatchesRegularExpression(
            '/plugins\s*\{[^}]*id\("com\.google\.gms\.google-services"\)[^}]*\n\}/s',
            $this->rootBuildFile()
        );
    }

    /**
     * @test
     *
     * The declaration is not written into the project template but into the
     * generated root build file, because `native:install` copies the template
     * once and nothing rewrites it afterwards. A project that already exists
     * has to be repaired by a build, or the fix reaches nobody who has run
     * this tool before.
     */
    public function it_repairs_a_project_that_already_exists(): void
    {
        $this->addGoogleServicesJson();

        // Exactly what an installed project holds today: the shipped template,
        // with no knowledge of google-services.
        $this->assertStringNotContainsString('google-services', $this->rootBuildFile());

        $this->runUpdateFirebaseConfiguration();

        $this->assertStringContainsString('com.google.gms.google-services', $this->rootBuildFile());
    }

    /**
     * @test
     *
     * `apply false` still resolves the plugin marker, so an app that is not
     * using Firebase should not be made to download one.
     */
    public function it_declares_nothing_for_a_project_without_firebase(): void
    {
        $this->runUpdateFirebaseConfiguration();

        $this->assertStringNotContainsString('google-services', $this->rootBuildFile());
        $this->assertSame(self::ROOT_BUILD_FILE, $this->rootBuildFile());
    }

    /**
     * @test
     *
     * Removing google-services.json takes the declaration back out. The block
     * is rebuilt from the current state on every build rather than patched, so
     * turning Firebase off is a build rather than a manual edit.
     */
    public function it_removes_the_declaration_when_the_config_file_goes_away(): void
    {
        $this->addGoogleServicesJson();
        $this->runUpdateFirebaseConfiguration();
        $this->assertStringContainsString('com.google.gms.google-services', $this->rootBuildFile());

        File::delete($this->testProjectPath.'/google-services.json');
        File::delete($this->testProjectPath.'/nativephp/android/app/google-services.json');

        $this->runUpdateFirebaseConfiguration();

        $this->assertStringNotContainsString('google-services', $this->rootBuildFile());
        $this->assertSame(self::ROOT_BUILD_FILE, $this->rootBuildFile());
    }

    /**
     * @test
     *
     * Five builds, one declaration, and the file is byte-identical after the
     * first one. A build file that changes on every build takes reproducibility
     * away from everything downstream of it.
     */
    public function it_is_idempotent_across_repeated_builds(): void
    {
        $this->addGoogleServicesJson();

        $this->runUpdateFirebaseConfiguration();
        $afterFirst = $this->rootBuildFile();

        for ($i = 0; $i < 4; $i++) {
            $this->runUpdateFirebaseConfiguration();
        }

        $this->assertSame($afterFirst, $this->rootBuildFile());
        $this->assertSame(1, substr_count($afterFirst, 'BEGIN nativephp-google-services'));
        $this->assertSame(1, substr_count($afterFirst, 'id("com.google.gms.google-services")'));
    }

    /**
     * @test
     *
     * A plugin can already declare this through `android.gradle_plugins`, and
     * a project can have it hand-written. Declaring the same plugin id twice
     * is a Gradle error, so an existing declaration wins.
     */
    public function it_leaves_an_existing_declaration_alone(): void
    {
        $this->addGoogleServicesJson();

        File::put($this->testProjectPath.'/nativephp/android/build.gradle.kts', <<<'KTS'
            plugins {
                alias(libs.plugins.android.application) apply false
                // BEGIN nativephp-plugin-gradle-plugins
                id("com.google.gms.google-services") version "4.4.0" apply false
                // END nativephp-plugin-gradle-plugins
            }

            KTS);

        $this->runUpdateFirebaseConfiguration();

        $content = $this->rootBuildFile();
        $this->assertSame(1, substr_count($content, 'id("com.google.gms.google-services")'));
        $this->assertStringContainsString('version "4.4.0"', $content);
        $this->assertStringNotContainsString('BEGIN nativephp-google-services', $content);
    }

    /**
     * @test
     *
     * The same, for a project that puts it on the buildscript classpath
     * instead — the shape the Firebase documentation used before the plugins
     * DSL, and the shape people patch in by hand when they hit this.
     */
    public function it_leaves_a_hand_written_buildscript_classpath_alone(): void
    {
        $this->addGoogleServicesJson();

        File::put($this->testProjectPath.'/nativephp/android/build.gradle.kts', <<<'KTS'
            buildscript {
                dependencies {
                    classpath("com.google.gms:google-services:4.4.2")
                }
            }
            plugins {
                alias(libs.plugins.android.application) apply false
            }

            KTS);

        $this->runUpdateFirebaseConfiguration();

        $this->assertStringNotContainsString('BEGIN nativephp-google-services', $this->rootBuildFile());
        $this->assertSame(1, substr_count($this->rootBuildFile(), 'google-services'));
    }

    /**
     * @test
     *
     * The declaration and the plugin compiler's own block are written by
     * different code paths into the same file. `buildGradlePluginsBlock`
     * already skips an id declared outside its markers; this pins that it
     * sees ours, because a duplicate would fail the build the fix exists to
     * unbreak.
     */
    public function the_plugin_compiler_does_not_declare_it_a_second_time(): void
    {
        $this->addGoogleServicesJson();
        $this->runUpdateFirebaseConfiguration();

        $plugin = $this->makePluginDeclaringGoogleServices();

        $compiler = new AndroidPluginCompiler(
            new Filesystem,
            $this->createMock(PluginRegistry::class),
            $this->testProjectPath.'/nativephp'
        );

        $block = $compiler->buildGradlePluginsBlock(
            new Collection([$plugin]),
            $this->rootBuildFile()
        );

        $this->assertSame('', $block);
    }

    /**
     * @test
     *
     * Nothing to repair and nothing to crash on: `native:run` on a project
     * whose Android folder has not been generated yet.
     */
    public function it_does_nothing_without_an_android_project(): void
    {
        File::delete($this->testProjectPath.'/nativephp/android/build.gradle.kts');
        $this->addGoogleServicesJson();

        $this->runUpdateFirebaseConfiguration();

        $this->assertFileDoesNotExist($this->testProjectPath.'/nativephp/android/build.gradle.kts');
        $this->assertSame([], $this->warnings);
    }

    /**
     * @test
     *
     * A root build file with no plugins {} block cannot be repaired. Saying so
     * is the whole point — a silent no-op here is the failure this fix is
     * about, one layer further out.
     */
    public function it_warns_when_there_is_no_plugins_block_to_declare_it_in(): void
    {
        File::put($this->testProjectPath.'/nativephp/android/build.gradle.kts', "// nothing here\n");
        $this->addGoogleServicesJson();

        $this->runUpdateFirebaseConfiguration();

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('com.google.gms.google-services', $this->warnings[0]);
        $this->assertSame("// nothing here\n", $this->rootBuildFile());
    }

    /**
     * @test
     *
     * And it stays quiet about a project that never asked for Firebase.
     */
    public function it_does_not_warn_about_a_project_without_firebase(): void
    {
        File::put($this->testProjectPath.'/nativephp/android/build.gradle.kts', "// nothing here\n");

        $this->runUpdateFirebaseConfiguration();

        $this->assertSame([], $this->warnings);
    }

    /**
     * @test
     *
     * The copy this method already did keeps working, unchanged.
     */
    public function it_still_copies_the_config_into_the_app_module(): void
    {
        $this->addGoogleServicesJson();

        $this->runUpdateFirebaseConfiguration();

        $target = $this->testProjectPath.'/nativephp/android/app/google-services.json';
        $this->assertFileExists($target);
        $this->assertSame('{"project_id": "test"}', File::get($target));
    }

    /**
     * @test
     *
     * nativephp/resources/google-services.json wins over the project root, and
     * either of them turns the declaration on.
     */
    public function it_reads_the_config_from_the_resources_directory_too(): void
    {
        File::ensureDirectoryExists($this->testProjectPath.'/nativephp/resources');
        File::put($this->testProjectPath.'/nativephp/resources/google-services.json', '{"project_id": "resources"}');

        $this->runUpdateFirebaseConfiguration();

        $this->assertSame(
            '{"project_id": "resources"}',
            File::get($this->testProjectPath.'/nativephp/android/app/google-services.json')
        );
        $this->assertStringContainsString('com.google.gms.google-services', $this->rootBuildFile());
    }

    /**
     * Supplied by PlatformFileOperations in the real command; nothing under
     * test here calls it.
     */
    protected function removeDirectory(string $path): void
    {
        File::deleteDirectory($path);
    }

    protected function makePluginDeclaringGoogleServices(): Plugin
    {
        return new Plugin(
            name: 'test/gms-plugin',
            version: '1.0.0',
            path: $this->testProjectPath.'/plugins/gms-plugin',
            manifest: new PluginManifest([
                'name' => 'test/gms-plugin',
                'namespace' => 'Gms',
                'android' => [
                    'gradle_plugins' => [
                        ['id' => 'com.google.gms.google-services', 'version' => '4.4.3'],
                    ],
                ],
            ]),
        );
    }
}
