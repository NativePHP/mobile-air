<?php

use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\MarkerScreen;

/**
 * Blade's compiled cache is keyed by path + mtime only — not by whether
 * the native precompiler was active. These tests prove that a view (or
 * partial) compiled by a web render / view:cache is transparently
 * recompiled when a native render needs it, instead of being included
 * as plain HTML and collecting zero elements.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../Fixtures/views');
});

/** Compile a view the way a web render would: precompiler inactive. */
function compileAsWeb(string $viewName): string
{
    $path = app('view')->getFinder()->find($viewName);

    $compiler = app('blade.compiler');
    $compiler->compile($path);

    return $compiler->getCompiledPath($path);
}

it('stamps natively compiled views with the marker', function () {
    Native::test(MarkerScreen::class)->assertSee('Root content');

    $compiled = app('blade.compiler')->getCompiledPath(
        app('view')->getFinder()->find('marker-screen')
    );

    expect(file_get_contents($compiled))->toContain(NativeTagPrecompiler::COMPILED_MARKER);
});

it('recompiles a root view that a web render compiled first', function () {
    $compiled = compileAsWeb('marker-screen');

    expect(file_get_contents($compiled))->not->toContain(NativeTagPrecompiler::COMPILED_MARKER);

    Native::test(MarkerScreen::class)
        ->assertSee('Root content')
        ->assertSee('Partial content');

    expect(file_get_contents($compiled))->toContain(NativeTagPrecompiler::COMPILED_MARKER);
});

it('recompiles a nested partial that a web render compiled first', function () {
    // Prime the root as natively-compiled, then poison only the partial —
    // recovery must come from the engine (the root check can't see it).
    Native::test(MarkerScreen::class);

    $compiledPartial = compileAsWeb('marker-partial');

    expect(file_get_contents($compiledPartial))->not->toContain(NativeTagPrecompiler::COMPILED_MARKER);

    Native::test(MarkerScreen::class)->assertSee('Partial content');

    expect(file_get_contents($compiledPartial))->toContain(NativeTagPrecompiler::COMPILED_MARKER);
});

it('leaves non-native compilation untouched', function () {
    $compiled = compileAsWeb('marker-screen');

    // A web compile of a native view produces no marker and no collector
    // calls — the tags pass through as markup, exactly as before.
    $contents = file_get_contents($compiled);

    expect($contents)->not->toContain(NativeTagPrecompiler::COMPILED_MARKER)
        ->and($contents)->not->toContain('NativeElementCollector');
});
