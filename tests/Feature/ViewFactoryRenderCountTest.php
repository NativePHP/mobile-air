<?php

use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ComponentSlotScreen;

/**
 * renderBladeBoundToSelf() evaluates the compiled view via a direct
 * include, outside Factory::render(). It must participate in the
 * factory's render counting: otherwise the first nested @include drops
 * the count to 0 on completion and flushState() wipes component slot
 * storage mid-render, crashing any open <x-*> Blade component
 * ("Undefined array key 0" from the flushed slots array).
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../Fixtures/views');
});

it('renders an @include inside an open <x-*> component without flushing slot state', function () {
    Native::test(ComponentSlotScreen::class)
        ->assertSee('Slot content')
        ->assertSee('Partial content')
        ->assertSee('After component');
});

it('leaves the view factory done rendering after a native render', function () {
    Native::test(ComponentSlotScreen::class);

    expect(app('view')->doneRendering())->toBeTrue();
});
