<?php

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * Plugins that replace a file core installs verbatim restore it by reading
 * core's own copy. These cover the accessors that locate that copy, so a
 * plugin never has to hardcode a path into another package's vendor tree.
 */
function hookCommand(): NativePluginHookCommand
{
    return new class extends NativePluginHookCommand
    {
        protected $signature = 'nativephp:test:hook';

        public function templatePath(string $path = ''): string
        {
            return $this->coreTemplatePath($path);
        }

        public function ios(string $path = ''): string
        {
            return $this->iosTemplatePath($path);
        }

        public function android(string $path = ''): string
        {
            return $this->androidTemplatePath($path);
        }
    };
}

it('resolves the package template root', function () {
    expect(hookCommand()->templatePath())
        ->toBe(realpath(__DIR__.'/../../../resources'))
        ->and(is_dir(hookCommand()->templatePath()))->toBeTrue();
});

it('resolves files core installs verbatim', function () {
    expect(hookCommand()->ios('NativePHP/LaunchScreen.storyboard'))
        ->toEndWith('/resources/xcode/NativePHP/LaunchScreen.storyboard')
        ->and(file_exists(hookCommand()->ios('NativePHP/LaunchScreen.storyboard')))->toBeTrue()
        ->and(file_exists(hookCommand()->android('app/src/main/AndroidManifest.xml')))->toBeTrue();
});

/**
 * The path is appended, so a caller may write it with or without the
 * separator core already adds.
 */
it('takes a relative path either way', function () {
    expect(hookCommand()->ios('/NativePHP'))->toBe(hookCommand()->ios('NativePHP'))
        ->and(hookCommand()->android())->toEndWith('/resources/androidstudio');
});
