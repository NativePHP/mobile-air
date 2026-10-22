<?php

use Illuminate\Support\Facades\File;
use Native\Mobile\Concerns\InstallsCustomIcons;
use Native\Mobile\Icon\Svg\NormalisedSvgWriter;
use Native\Mobile\Icon\Svg\SvgConversionException;
use Native\Mobile\Icon\Svg\SvgParser;
use Native\Mobile\Icon\Svg\VectorDrawableWriter;

/**
 * The build steps own their output whole: each build writes exactly the
 * icons in `resources/icons` today, and clears whatever an earlier build
 * left behind.
 */
beforeEach(function () {
    $this->projectPath = sys_get_temp_dir().'/nativephp_custom_icons_test_'.uniqid();
    $this->iconsPath = $this->projectPath.'/resources/icons';
    $this->catalogPath = $this->projectPath.'/nativephp/ios/NativePHP/Assets.xcassets/CustomIcons';
    $this->resPath = $this->projectPath.'/nativephp/android/app/src/main/res';
    $this->keepFile = $this->resPath.'/raw/nativephp_custom_icons_keep.xml';

    File::makeDirectory($this->iconsPath, 0755, true);
    File::makeDirectory($this->resPath.'/drawable', 0755, true);

    app()->setBasePath($this->projectPath);

    $this->builder = new class
    {
        use InstallsCustomIcons;
    };

    $this->addIcon = function (string $name, ?string $svg = null) {
        File::put($this->iconsPath.'/'.$name.'.svg', $svg ?? file_get_contents(__DIR__.'/../../Fixtures/Icons/lucide/'.$name.'.svg'));
    };
});

afterEach(function () {
    File::deleteDirectory($this->projectPath);
});

it('writes a namespaced template image set per icon for iOS', function () {
    ($this->addIcon)('git-commit-vertical');
    ($this->addIcon)('bell');

    expect($this->builder->installIosCustomIcons())->toBe(['bell', 'git-commit-vertical']);

    expect(File::get($this->catalogPath.'/Contents.json'))
        ->toBe('{"info":{"author":"xcode","version":1},"properties":{"provides-namespace":true}}');

    expect(File::get($this->catalogPath.'/git-commit-vertical.imageset/Contents.json'))
        ->toBe('{"images":[{"filename":"git-commit-vertical.svg","idiom":"universal"}],"info":{"author":"xcode","version":1},"properties":{"preserves-vector-representation":true,"template-rendering-intent":"template"}}');

    $source = file_get_contents(__DIR__.'/../../Fixtures/Icons/lucide/git-commit-vertical.svg');

    expect(File::get($this->catalogPath.'/git-commit-vertical.imageset/git-commit-vertical.svg'))
        ->toBe(NormalisedSvgWriter::write(SvgParser::parse($source, 'git-commit-vertical.svg')));

    expect(File::files($this->catalogPath.'/bell.imageset'))->toHaveCount(2);
});

it('drops iOS icons the app no longer ships', function () {
    ($this->addIcon)('bell');
    ($this->addIcon)('star');
    $this->builder->installIosCustomIcons();

    File::delete($this->iconsPath.'/star.svg');
    File::put($this->catalogPath.'/stray.txt', 'left by something else');

    expect($this->builder->installIosCustomIcons())->toBe(['bell']);

    expect($this->catalogPath.'/bell.imageset')->toBeDirectory()
        ->and($this->catalogPath.'/star.imageset')->not->toBeDirectory()
        ->and($this->catalogPath.'/stray.txt')->not->toBeFile();
});

it('removes the iOS folder when the app has no icons', function (?string $leftover) {
    ($this->addIcon)('bell');
    $this->builder->installIosCustomIcons();

    File::delete($this->iconsPath.'/bell.svg');

    if ($leftover === null) {
        File::deleteDirectory($this->iconsPath);
    } else {
        File::put($this->iconsPath.'/'.$leftover, 'not an icon');
    }

    expect($this->builder->installIosCustomIcons())->toBe([])
        ->and($this->catalogPath)->not->toBeDirectory();
})->with([
    'no resources/icons folder' => null,
    'only non-svg files' => 'README.md',
    'a dotfile' => '.DS_Store',
]);

it('ignores svgs in subfolders', function () {
    File::makeDirectory($this->iconsPath.'/drafts');
    File::put($this->iconsPath.'/drafts/bell.svg', file_get_contents(__DIR__.'/../../Fixtures/Icons/lucide/bell.svg'));

    expect($this->builder->installIosCustomIcons())->toBe([])
        ->and($this->builder->installAndroidCustomIcons())->toBe([]);
});

it('unlinks a symlinked iOS folder without touching its target', function () {
    $target = $this->projectPath.'/shared-icons';
    File::makeDirectory($target);
    File::put($target.'/keep-me.txt', 'owned by the app');
    File::ensureDirectoryExists(dirname($this->catalogPath));
    symlink($target, $this->catalogPath);

    ($this->addIcon)('bell');
    $this->builder->installIosCustomIcons();

    expect(is_link($this->catalogPath))->toBeFalse()
        ->and($this->catalogPath.'/bell.imageset')->toBeDirectory()
        ->and($target.'/keep-me.txt')->toBeFile();
});

it('writes VectorDrawables and the keep file for Android', function () {
    ($this->addIcon)('git-commit-vertical');
    ($this->addIcon)('settings-2');

    expect($this->builder->installAndroidCustomIcons())->toBe(['git-commit-vertical', 'settings-2']);

    $source = file_get_contents(__DIR__.'/../../Fixtures/Icons/lucide/git-commit-vertical.svg');

    expect(File::get($this->resPath.'/drawable/nativephp_custom_git_commit_vertical.xml'))
        ->toBe(VectorDrawableWriter::write(SvgParser::parse($source, 'git-commit-vertical.svg')))
        ->and($this->resPath.'/drawable/nativephp_custom_settings_2.xml')->toBeFile();

    expect(File::get($this->keepFile))
        ->toBe('<resources xmlns:tools="http://schemas.android.com/tools" tools:keep="@drawable/nativephp_custom_*" />'."\n");
});

it('drops Android icons the app no longer ships and leaves other drawables alone', function () {
    File::put($this->resPath.'/drawable/nativephp_custom_old_icon.xml', '<vector/>');
    File::put($this->resPath.'/drawable/splash_background.xml', '<layer-list/>');
    File::put($this->resPath.'/drawable/nativephp_custom_notes.txt', 'not a drawable this step wrote');

    ($this->addIcon)('bell');
    $this->builder->installAndroidCustomIcons();

    expect($this->resPath.'/drawable/nativephp_custom_bell.xml')->toBeFile()
        ->and($this->resPath.'/drawable/nativephp_custom_old_icon.xml')->not->toBeFile()
        ->and($this->resPath.'/drawable/splash_background.xml')->toBeFile()
        ->and($this->resPath.'/drawable/nativephp_custom_notes.txt')->toBeFile();
});

it('removes the drawables and keep file when the app has no icons', function () {
    ($this->addIcon)('bell');
    $this->builder->installAndroidCustomIcons();

    expect($this->keepFile)->toBeFile();

    File::delete($this->iconsPath.'/bell.svg');

    expect($this->builder->installAndroidCustomIcons())->toBe([])
        ->and($this->resPath.'/drawable/nativephp_custom_bell.xml')->not->toBeFile()
        ->and($this->keepFile)->not->toBeFile();
});

it('fails on an svg whose name cannot be an icon name', function (string $method) {
    File::put($this->iconsPath.'/Bell_Icon.svg', file_get_contents(__DIR__.'/../../Fixtures/Icons/lucide/bell.svg'));

    expect(fn () => $this->builder->{$method}())->toThrow(
        SvgConversionException::class,
        'resources/icons/Bell_Icon.svg: custom icon file names must be lowercase letters, numbers and single hyphens'
    );
})->with(['installIosCustomIcons', 'installAndroidCustomIcons']);

it('fails on an unsupported svg before touching earlier output', function () {
    ($this->addIcon)('bell');
    $this->builder->installIosCustomIcons();
    $this->builder->installAndroidCustomIcons();

    ($this->addIcon)('star', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g transform="rotate(45)"><path d="M0 0h1"/></g></svg>');

    expect(fn () => $this->builder->installIosCustomIcons())
        ->toThrow(SvgConversionException::class, 'resources/icons/star.svg: attribute "transform" on <g>');
    expect(fn () => $this->builder->installAndroidCustomIcons())
        ->toThrow(SvgConversionException::class, 'resources/icons/star.svg: attribute "transform" on <g>');

    expect($this->catalogPath.'/bell.imageset/bell.svg')->toBeFile()
        ->and($this->resPath.'/drawable/nativephp_custom_bell.xml')->toBeFile()
        ->and($this->keepFile)->toBeFile();
});
