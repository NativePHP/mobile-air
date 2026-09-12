<?php

namespace Tests\Unit\Plugins;

use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Plugins\LegacyFirebaseConfig;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;

class LegacyFirebaseConfigTest extends TestCase
{
    private Filesystem $files;

    private string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->projectPath = sys_get_temp_dir().'/nativephp-legacy-firebase-'.uniqid();
        $this->files->ensureDirectoryExists($this->projectPath.'/nativephp/android/app');
        $this->files->ensureDirectoryExists($this->projectPath.'/nativephp/ios/NativePHP');
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    /** @test */
    public function it_installs_android_config_for_a_plugin_that_predates_project_files(): void
    {
        $this->files->put($this->projectPath.'/google-services.json', '{"project_id":"legacy"}');

        $covered = $this->shim()->sync(collect([$this->oldPlugin()]), 'android');

        $this->assertSame('vendor/firebase-plugin', $covered);
        $this->assertSame(
            '{"project_id":"legacy"}',
            $this->files->get($this->projectPath.'/nativephp/android/app/google-services.json')
        );
    }

    /** @test */
    public function it_installs_ios_config_for_a_plugin_that_predates_project_files(): void
    {
        $this->files->put($this->projectPath.'/GoogleService-Info.plist', '<plist/>');

        $covered = $this->shim()->sync(collect([$this->oldPlugin()]), 'ios');

        $this->assertSame('vendor/firebase-plugin', $covered);
        $this->assertFileExists($this->projectPath.'/nativephp/ios/NativePHP/GoogleService-Info.plist');
    }

    /**
     * @test
     *
     * The handover: once the plugin declares the destination itself,
     * ProjectFileManager owns the copy and core must keep its hands off.
     */
    public function it_stands_down_once_the_plugin_owns_the_destination(): void
    {
        $this->files->put($this->projectPath.'/google-services.json', 'root');

        $covered = $this->shim()->sync(collect([$this->newPlugin()]), 'android');

        $this->assertNull($covered);
        $this->assertFileDoesNotExist($this->projectPath.'/nativephp/android/app/google-services.json');
    }

    /**
     * @test
     *
     * A stray config file with no Firebase plugin installed is not core's
     * business — copying it in is what made the Gradle build fail.
     */
    public function it_ignores_a_config_file_when_no_plugin_wants_firebase(): void
    {
        $this->files->put($this->projectPath.'/google-services.json', 'root');
        $this->files->put($this->projectPath.'/GoogleService-Info.plist', '<plist/>');

        $unrelated = $this->plugin('vendor/unrelated', []);

        $this->assertNull($this->shim()->sync(collect([$unrelated]), 'android'));
        $this->assertNull($this->shim()->sync(collect([$unrelated]), 'ios'));
        $this->assertFileDoesNotExist($this->projectPath.'/nativephp/android/app/google-services.json');
        $this->assertFileDoesNotExist($this->projectPath.'/nativephp/ios/NativePHP/GoogleService-Info.plist');
    }

    /** @test */
    public function it_does_nothing_when_there_is_no_config_file_to_install(): void
    {
        $this->assertNull($this->shim()->sync(collect([$this->oldPlugin()]), 'android'));
        $this->assertFileDoesNotExist($this->projectPath.'/nativephp/android/app/google-services.json');
    }

    private function shim(): LegacyFirebaseConfig
    {
        return new LegacyFirebaseConfig($this->files, $this->projectPath.'/nativephp');
    }

    /** A pre-`project_files` Firebase plugin: Gradle plugin + pods, no ownership. */
    private function oldPlugin(): Plugin
    {
        return $this->plugin('vendor/firebase-plugin', [
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.google.gms.google-services', 'version' => '4.4.3'],
                ],
            ],
            'ios' => [
                'dependencies' => ['pods' => [['name' => 'FirebaseMessaging', 'version' => '~> 12.6.0']]],
            ],
        ]);
    }

    /** The same plugin once it declares its own config files. */
    private function newPlugin(): Plugin
    {
        return $this->plugin('vendor/firebase-plugin', [
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.google.gms.google-services', 'version' => '4.4.3', 'apply_to' => 'app'],
                ],
                'project_files' => [[
                    'sources' => ['google-services.json'],
                    'destination' => 'app/google-services.json',
                    'required' => true,
                ]],
            ],
        ]);
    }

    private function plugin(string $name, array $platforms): Plugin
    {
        return new Plugin(
            name: $name,
            version: '1.0.0',
            path: $this->projectPath.'/vendor/'.$name,
            manifest: new PluginManifest(array_merge([
                'namespace' => 'Firebase',
            ], $platforms)),
        );
    }
}
