<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use Native\Mobile\Support\IosProductName;
use ReflectionClass;
use Tests\TestCase;

/**
 * The template's app target is "NativePHP" with PRODUCT_NAME = $(TARGET_NAME),
 * so every app was built as NativePHP.app with a NativePHP executable, and
 * App Store archives kept CFBundleName = NativePHP.
 */
class IosProductNameTest extends TestCase
{
    protected string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_ios_product_name_test_'.uniqid().'/NativePHP.xcodeproj';
        File::ensureDirectoryExists($this->projectPath);
        File::copy(dirname(__DIR__, 2).'/resources/xcode/NativePHP.xcodeproj/project.pbxproj', $this->projectPath.'/project.pbxproj');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->projectPath));

        parent::tearDown();
    }

    public function test_the_product_name_comes_from_the_app_name(): void
    {
        config(['app.name' => 'Firstlight Showcase', 'nativephp.ios.product_name' => null]);
        $this->assertSame('Firstlight Showcase', IosProductName::resolve());

        config(['app.name' => ' Café & Crème: "Paris"/Lyon. ']);
        $this->assertSame('Cafe Creme ParisLyon', IosProductName::resolve());

        config(['nativephp.ios.product_name' => 'Showcase']);
        $this->assertSame('Showcase', IosProductName::resolve());

        config(['app.name' => '東京 🚀', 'nativephp.ios.product_name' => null]);
        $this->assertSame('NativePHP', IosProductName::resolve());
    }

    public function test_only_the_app_target_is_renamed_and_its_module_stays_native_php(): void
    {
        $this->updateProductName('Firstlight Showcase');

        $this->assertSame([
            'NativePHP' => ['Debug' => ['"Firstlight Showcase"', 'NativePHP'], 'Release' => ['"Firstlight Showcase"', 'NativePHP']],
            'NativePHP-simulator' => ['Debug' => ['"$(TARGET_NAME)"', null], 'Release' => ['"$(TARGET_NAME)"', null]],
            'NativePHPTests' => ['Debug' => ['"$(TARGET_NAME)"', null], 'Release' => ['"$(TARGET_NAME)"', null]],
            'NativePHPUITests' => ['Debug' => ['"$(TARGET_NAME)"', null], 'Release' => ['"$(TARGET_NAME)"', null]],
        ], $this->productSettings());

        $this->assertSame(2, substr_count(
            File::get($this->projectPath.'/project.pbxproj'),
            'TEST_HOST = "$(BUILT_PRODUCTS_DIR)/Firstlight Showcase.app/$(BUNDLE_EXECUTABLE_FOLDER_PATH)/Firstlight Showcase";'
        ));
    }

    public function test_a_renamed_app_can_be_renamed_again(): void
    {
        $this->updateProductName('Firstlight Showcase');
        $this->updateProductName('Showcase');

        $pbxproj = File::get($this->projectPath.'/project.pbxproj');

        $this->assertSame(['"Showcase"', 'NativePHP'], $this->productSettings()['NativePHP']['Release']);
        $this->assertSame(2, substr_count($pbxproj, 'PRODUCT_MODULE_NAME = NativePHP;'));
        $this->assertStringNotContainsString('Firstlight', $pbxproj);
    }

    private function updateProductName(string $name): void
    {
        $command = new BuildIosAppCommand;
        $reflection = new ReflectionClass($command);
        $reflection->getProperty('xcodeProjectPath')->setValue($command, $this->projectPath);
        $reflection->getMethod('updateProductName')->invoke($command, $name);
    }

    /**
     * PRODUCT_NAME and PRODUCT_MODULE_NAME of every target's configurations.
     *
     * @return array<string, array<string, array{0: ?string, 1: ?string}>>
     */
    private function productSettings(): array
    {
        $pbxproj = File::get($this->projectPath.'/project.pbxproj');

        preg_match_all('/Build configuration list for PBXNativeTarget "([^"]+)" \*\/ = \{.*?buildConfigurations = \((.*?)\);/s', $pbxproj, $lists, PREG_SET_ORDER);

        $settings = [];

        foreach ($lists as [, $target, $ids]) {
            preg_match_all('/([0-9A-F]{24}) \/\* (\w+) \*\//', $ids, $configurations, PREG_SET_ORDER);

            foreach ($configurations as [, $id, $name]) {
                preg_match('/\t\t'.$id.' \/\* \w+ \*\/ = \{\n\t\t\tisa = XCBuildConfiguration;.*?\n\t\t\};/s', $pbxproj, $block);
                preg_match('/\tPRODUCT_NAME = (.+);/', $block[0], $product);
                preg_match('/\tPRODUCT_MODULE_NAME = (.+);/', $block[0], $module);

                $settings[$target][$name] = [$product[1] ?? null, $module[1] ?? null];
            }
        }

        ksort($settings);

        return $settings;
    }
}
