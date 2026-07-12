<?php

namespace Tests\Feature\Plugins;

use Illuminate\Filesystem\Filesystem;
use Mockery;
use Native\Mobile\Commands\BuildIosAppCommand;
use Native\Mobile\Plugins\Compilers\IOS\ExtensionTargetCompiler;
use Native\Mobile\Plugins\Compilers\IOS\PropertyList;
use Native\Mobile\Plugins\IOS\ExtensionProvisioningProfileManager;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use Native\Mobile\Traits\ManagesIosSigning;
use Native\Mobile\Traits\PackagesIos;
use Tests\TestCase;

class IOSExtensionSigningIntegrationTest extends TestCase
{
    private const HOST_PROFILE_UUID = '11111111-2222-4333-8444-555555555555';

    private const EXTENSION_PROFILE_UUID = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';

    private Filesystem $files;

    private string $testPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testPath = sys_get_temp_dir().'/nativephp-ios-signing-'.uniqid();
        $this->files->ensureDirectoryExists($this->testPath.'/build');
        config()->set('nativephp.app_id', 'com.example.app');
        config()->set('nativephp.development_team', 'TEAMID1234');
    }

    protected function tearDown(): void
    {
        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV);
        putenv(ExtensionProvisioningProfileManager::METADATA_ENV);
        putenv('EXTRACTED_PROVISIONING_PROFILE_UUID');
        $this->files->deleteDirectory($this->testPath);
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_adds_the_installed_profile_to_extension_build_configurations(): void
    {
        $this->files->copyDirectory(
            dirname(__DIR__, 3).'/resources/xcode',
            $this->testPath.'/ios'
        );
        $this->storeExtensionProfileMetadata();

        (new ExtensionTargetCompiler(
            $this->files,
            $this->testPath.'/ios',
            'com.example.app'
        ))->compile(collect([$this->fixturePlugin()]));

        $project = $this->files->get($this->testPath.'/ios/NativePHP.xcodeproj/project.pbxproj');
        $setting = 'PROVISIONING_PROFILE_SPECIFIER = "'.self::EXTENSION_PROFILE_UUID.'";';

        $this->assertSame(2, substr_count($project, $setting));
        $this->assertSame(2, substr_count($project, 'CODE_SIGN_STYLE = Manual;'));
    }

    public function test_it_keeps_extension_signing_automatic_without_an_installed_profile(): void
    {
        $this->files->copyDirectory(
            dirname(__DIR__, 3).'/resources/xcode',
            $this->testPath.'/ios'
        );

        (new ExtensionTargetCompiler(
            $this->files,
            $this->testPath.'/ios',
            'com.example.app'
        ))->compile(collect([$this->fixturePlugin()]));

        $project = $this->files->get($this->testPath.'/ios/NativePHP.xcodeproj/project.pbxproj');
        preg_match_all('/APPLICATION_EXTENSION_API_ONLY = YES;.*?CODE_SIGN_STYLE = Automatic;/s', $project, $matches);

        $this->assertCount(2, $matches[0]);
        $this->assertStringNotContainsString('PROVISIONING_PROFILE_SPECIFIER', $project);
    }

    public function test_export_options_map_the_host_and_extension_profiles(): void
    {
        putenv('EXTRACTED_PROVISIONING_PROFILE_UUID='.self::HOST_PROFILE_UUID);
        $this->storeExtensionProfileMetadata();

        $exporter = new class
        {
            use PackagesIos;

            public object $components;

            public function __construct()
            {
                $this->components = new class
                {
                    public function twoColumnDetail(string $label, string $value): void {}
                };
            }

            public function createOptions(string $path): string
            {
                return $this->createExportOptions($path);
            }

            public function option(string $name): mixed
            {
                return $name === 'export-method' ? 'app-store' : null;
            }
        };

        $path = $exporter->createOptions($this->testPath);
        $options = (new PropertyList)->decode($this->files->get($path));

        $this->assertSame(self::HOST_PROFILE_UUID, $options['provisioningProfiles']['com.example.app']);
        $this->assertSame(
            self::EXTENSION_PROFILE_UUID,
            $options['provisioningProfiles']['com.example.app.widgets']
        );
    }

    public function test_manual_signing_fails_early_without_an_extension_profile(): void
    {
        $registry = Mockery::mock(PluginRegistry::class);
        $registry->shouldReceive('all')->once()->andReturn(collect([$this->fixturePlugin()]));
        app()->instance(PluginRegistry::class, $registry);
        putenv(ExtensionProvisioningProfileManager::METADATA_ENV.'={"stale":{"uuid":"value"}}');

        $signer = new class
        {
            use ManagesIosSigning;

            public object $components;

            public function __construct()
            {
                $this->components = new class
                {
                    public function twoColumnDetail(string $label, string $value): void {}
                };
            }

            public function installExtensionProfiles(string $exportMethod): bool
            {
                return $this->setupExtensionProvisioningProfiles($exportMethod);
            }

            public function line(string $message): void {}
        };

        $this->assertFalse($signer->installExtensionProfiles('app-store'));
        $this->assertSame('{}', getenv(ExtensionProvisioningProfileManager::METADATA_ENV));
    }

    public function test_cleanup_restores_managed_extensions_to_automatic_signing(): void
    {
        $projectDirectory = $this->testPath.'/NativePHP.xcodeproj';
        $this->files->ensureDirectoryExists($projectDirectory);
        $this->files->put($projectDirectory.'/project.pbxproj', <<<'PBX'
111111111111111111111111 /* Release */ = {
	isa = XCBuildConfiguration;
	buildSettings = {
		CODE_SIGN_ENTITLEMENTS = NativePHP/NativePHP.entitlements;
		CODE_SIGN_STYLE = Manual;
		PROVISIONING_PROFILE_SPECIFIER = "Host Profile";
	};
};
222222222222222222222222 /* Custom */ = {
	isa = XCBuildConfiguration;
	buildSettings = {
		CODE_SIGN_STYLE = Manual;
		PROVISIONING_PROFILE_SPECIFIER = "Custom Target Profile";
	};
};
/* NATIVEPHP_EXTENSIONS_BEGIN XCBuildConfiguration */
APPLICATION_EXTENSION_API_ONLY = YES;
CODE_SIGN_STYLE = Manual;
PROVISIONING_PROFILE_SPECIFIER = "AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE";
/* NATIVEPHP_EXTENSIONS_END XCBuildConfiguration */
PBX);
        $command = new BuildIosAppCommand;
        $property = new \ReflectionProperty($command, 'xcodeProjectPath');
        $property->setValue($command, $projectDirectory);
        $this->storeExtensionProfileMetadata();

        $command->cleanupProvisioningProfileConfiguration();

        $project = $this->files->get($projectDirectory.'/project.pbxproj');
        $this->assertSame(2, substr_count($project, 'CODE_SIGN_STYLE = Manual;'));
        $this->assertSame(1, substr_count($project, 'CODE_SIGN_STYLE = Automatic;'));
        $this->assertSame(1, substr_count($project, 'PROVISIONING_PROFILE_SPECIFIER'));
        $this->assertStringContainsString('Custom Target Profile', $project);
        $this->assertStringNotContainsString('Host Profile', $project);
        $this->assertSame('{}', getenv(ExtensionProvisioningProfileManager::METADATA_ENV));
    }

    private function fixturePlugin(): Plugin
    {
        $path = dirname(__DIR__, 2).'/Fixtures/plugins/widget-extension-plugin';

        return new Plugin(
            'nativephp/widgets',
            '1.0.0',
            $path,
            PluginManifest::fromFile($path.'/nativephp.json')
        );
    }

    private function storeExtensionProfileMetadata(): void
    {
        $metadata = [
            'com.example.app.widgets' => [
                'uuid' => self::EXTENSION_PROFILE_UUID,
                'name' => 'Widgets Distribution',
            ],
        ];

        putenv(ExtensionProvisioningProfileManager::METADATA_ENV.'='.json_encode($metadata));
    }
}
