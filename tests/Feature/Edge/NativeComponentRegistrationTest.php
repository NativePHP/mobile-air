<?php

use Illuminate\Support\Facades\Blade;
use Native\Mobile\Edge\Components\Native\Column;

/*
 * The service provider discovers the built-in components by walking
 * src/Edge/Components and turning each file's path into a class name. That
 * path arithmetic used to break on Windows — the base prefix never matched,
 * so every derived class name came out as
 * `Native\Mobile\Edge\Components\C:\…`, class_exists() returned false, and
 * not a single <native:*> component was registered. Nothing failed loudly;
 * the components simply never rendered, which is what you hit when serving
 * an app to the Jump client from a Windows machine.
 */

it('registers the built-in native components as blade components', function () {
    $aliases = Blade::getClassComponentAliases();

    expect($aliases)->toHaveKey('native-column')
        ->and($aliases['native-column'])->toBe(Column::class);
});

it('registers every component file it finds', function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            dirname(__DIR__, 3).'/src/Edge/Components',
            RecursiveDirectoryIterator::SKIP_DOTS
        )
    );

    $expected = 0;
    foreach ($files as $file) {
        // NativeBladeComponent is the abstract base and is skipped on purpose.
        if ($file->getExtension() === 'php' && $file->getBasename('.php') !== 'NativeBladeComponent') {
            $expected++;
        }
    }

    $registered = array_filter(
        Blade::getClassComponentAliases(),
        fn (string $class) => str_starts_with($class, 'Native\\Mobile\\Edge\\Components\\')
    );

    expect($expected)->toBeGreaterThan(0)
        ->and($registered)->toHaveCount($expected);
});

it('never derives a class name containing a filesystem path', function () {
    // The exact shape of the Windows bug: an absolute path bleeding into the
    // namespace. Guards against a regression on any platform.
    foreach (Blade::getClassComponentAliases() as $alias => $class) {
        expect($class)->not->toContain(':')
            ->and($class)->not->toContain('/');
    }
});
