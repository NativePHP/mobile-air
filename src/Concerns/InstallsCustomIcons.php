<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\File;
use Native\Mobile\Icon\Icon;
use Native\Mobile\Icon\Svg\NormalisedSvgWriter;
use Native\Mobile\Icon\Svg\SvgConversionException;
use Native\Mobile\Icon\Svg\SvgIcon;
use Native\Mobile\Icon\Svg\SvgParser;
use Native\Mobile\Icon\Svg\VectorDrawableWriter;

/**
 * Turns the app's `resources/icons/<name>.svg` files into native assets,
 * addressed at runtime as `custom:<name>` (see [Icon::custom]).
 *
 *   - iOS: one template image set per icon under
 *     `Assets.xcassets/CustomIcons/` (a namespaced folder, so the runtime
 *     asset name is `CustomIcons/<name>`).
 *   - Android: `res/drawable/nativephp_custom_<name_with_underscores>.xml`
 *     VectorDrawables, plus a keep file so release resource shrinking
 *     doesn't strip drawables that are only ever looked up by name.
 *
 * Both steps own their output whole: each build writes exactly the icons
 * the app ships today, so a deleted SVG takes its asset with it. Every SVG
 * is converted before anything is written, and the first unsupported one
 * throws a [SvgConversionException] naming the file.
 */
trait InstallsCustomIcons
{
    /**
     * @return list<string> the icon names installed
     *
     * @throws SvgConversionException
     */
    public function installIosCustomIcons(): array
    {
        $icons = array_map(
            fn (string $path) => NormalisedSvgWriter::write($this->parseCustomIcon($path)),
            $this->customIconSources(),
        );

        $folder = base_path('nativephp/ios/NativePHP/Assets.xcassets/CustomIcons');

        // A symlinked folder is unlinked rather than recursed into, so the
        // link target's contents survive.
        if (is_link($folder)) {
            File::delete($folder);
        } else {
            File::deleteDirectory($folder);
        }

        if ($icons === []) {
            return [];
        }

        File::ensureDirectoryExists($folder);
        File::put($folder.'/Contents.json', $this->assetCatalogJson([
            'info' => ['author' => 'xcode', 'version' => 1],
            'properties' => ['provides-namespace' => true],
        ]));

        foreach ($icons as $name => $svg) {
            $imageSet = "{$folder}/{$name}.imageset";

            File::ensureDirectoryExists($imageSet);
            File::put("{$imageSet}/{$name}.svg", $svg);
            File::put("{$imageSet}/Contents.json", $this->assetCatalogJson([
                'images' => [['filename' => "{$name}.svg", 'idiom' => 'universal']],
                'info' => ['author' => 'xcode', 'version' => 1],
                'properties' => ['preserves-vector-representation' => true, 'template-rendering-intent' => 'template'],
            ]));
        }

        return array_keys($icons);
    }

    /**
     * @return list<string> the icon names installed
     *
     * @throws SvgConversionException
     */
    public function installAndroidCustomIcons(): array
    {
        $icons = array_map(
            fn (string $path) => VectorDrawableWriter::write($this->parseCustomIcon($path)),
            $this->customIconSources(),
        );

        $res = base_path('nativephp/android/app/src/main/res');
        $keepFile = $res.'/raw/nativephp_custom_icons_keep.xml';

        foreach (File::glob($res.'/drawable/nativephp_custom_*.xml') ?: [] as $stale) {
            File::delete($stale);
        }

        if ($icons === []) {
            File::delete($keepFile);

            return [];
        }

        File::ensureDirectoryExists($res.'/drawable');

        foreach ($icons as $name => $xml) {
            File::put($res.'/drawable/nativephp_custom_'.str_replace('-', '_', $name).'.xml', $xml);
        }

        File::ensureDirectoryExists($res.'/raw');
        File::put($keepFile, '<resources xmlns:tools="http://schemas.android.com/tools" tools:keep="@drawable/nativephp_custom_*" />'."\n");

        return array_keys($icons);
    }

    /**
     * `resources/icons/*.svg`, flat, keyed by icon name in name order.
     * Anything that isn't an SVG is ignored; an SVG whose name can't be an
     * icon name fails, since it could never be referenced.
     *
     * @return array<string, string> name => path
     *
     * @throws SvgConversionException
     */
    private function customIconSources(): array
    {
        $folder = resource_path('icons');

        if (! File::isDirectory($folder)) {
            return [];
        }

        $sources = [];

        foreach (File::files($folder) as $file) {
            if (strtolower($file->getExtension()) !== 'svg') {
                continue;
            }

            $name = $file->getBasename('.'.$file->getExtension());

            if (! Icon::isValidName($name)) {
                throw SvgConversionException::invalidName($this->customIconDisplayPath($file->getPathname()));
            }

            $sources[$name] = $file->getPathname();
        }

        ksort($sources);

        return $sources;
    }

    /**
     * @throws SvgConversionException
     */
    private function parseCustomIcon(string $path): SvgIcon
    {
        return SvgParser::parse(File::get($path), $this->customIconDisplayPath($path));
    }

    private function customIconDisplayPath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/\\');
    }

    /**
     * @param  array<string, mixed>  $contents
     */
    private function assetCatalogJson(array $contents): string
    {
        return json_encode($contents, JSON_UNESCAPED_SLASHES);
    }
}
