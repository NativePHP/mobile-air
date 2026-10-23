<?php

use Illuminate\Support\Str;
use League\Flysystem\WhitespacePathNormalizer;
use Native\Mobile\Support\BundleExclusions;

// The device's view:cache walks every path registered on the view finder
// and Symfony's Finder throws when any of them is missing. On device
// the package lives at vendor/nativephp/mobile with the exclusion
// paths stripped, so a package registration is only safe when
// it exists here AND survives the bundle packers (#322).
it('registers only package view paths that ship in the app bundle', function () {
    // Resolve ".." segments lexically so a path like src/../resources/views
    // is caught even when the directory no longer exists and realpath
    // falls back to the raw string, as Spatie's hasViews() does.
    $normalize = fn (string $path): string => '/'.(new WhitespacePathNormalizer)->normalizePath($path);

    // A registered dir is stripped when it is an excluded path itself or
    // sits anywhere below one, hence the slash-crossing second match.
    $strippedFromBundle = fn (string $path): bool => collect(BundleExclusions::VENDOR_PATHS)
        ->contains(fn ($pattern) => fnmatch($pattern, $path, FNM_PATHNAME)
            || fnmatch($pattern.'/*', $path));

    $packageRoot = $normalize(dirname(__DIR__, 2));

    $finder = app('view')->getFinder();

    // Keep only paths the package itself registers. The repo's own vendor/
    // holds the testbench skeleton and framework hints, which map to
    // the app side on device and can't be validated from here.
    $registered = collect($finder->getPaths())
        ->merge(collect($finder->getHints())->flatten())
        ->map($normalize)
        ->filter(fn ($path) => Str::startsWith($path, $packageRoot.'/')
            && ! Str::startsWith($path, $packageRoot.'/vendor/'));

    $missing = $registered->reject(fn ($path) => is_dir($path));
    expect($missing->values()->all())->toBe([]);

    $stripped = $registered->filter(fn ($path) => $strippedFromBundle(
        'vendor/nativephp/mobile/'.Str::after($path, $packageRoot.'/')
    ));
    expect($stripped->values()->all())->toBe([]);
});

it('keeps the package component views resolvable', function () {
    expect(view()->exists('nativephp-mobile::components.native-element-with-children'))->toBeTrue();
});
