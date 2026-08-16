<?php

use Illuminate\Support\Str;

// The bundle packers strip the package root resources/ dir, and the
// device's view:cache walks every registered path with a Finder
// that throws when one is missing. So no registration may
// ever point inside that directory (#322).
it('registers no view paths under the unshipped package resources directory', function () {
    $packageResources = realpath(__DIR__.'/../../resources');

    $finder = app('view')->getFinder();

    $registered = collect($finder->getPaths())
        ->merge(collect($finder->getHints())->flatten());

    $offenders = $registered
        ->map(fn ($path) => realpath($path) ?: $path)
        ->filter(fn ($path) => Str::startsWith($path, $packageResources.DIRECTORY_SEPARATOR));

    expect($offenders->values()->all())->toBe([]);
});

it('keeps the package component views resolvable', function () {
    expect(view()->exists('nativephp-mobile::components.native-element-with-children'))->toBeTrue();
});
