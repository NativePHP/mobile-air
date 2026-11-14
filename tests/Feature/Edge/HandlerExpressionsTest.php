<?php

use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\BottomSheet;
use Native\Mobile\Edge\Exceptions\InvalidHandlerException;
use Native\Mobile\Edge\Exceptions\LockedPropertyException;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\HandlerExpressionsScreen;
use Tests\Fixtures\Edge\SavingChild;

/**
 * Handlers that set a property (`@press="$open = true"`), and handlers that
 * cannot run, which are reported instead of dropped.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    // `bottom_sheet` normally comes from a UI plugin.
    $this->savedElements = ElementRegistry::all();
    ElementRegistry::register('bottom_sheet', BottomSheet::class);

    ComponentRegistry::reset();
    ComponentRegistry::components(['saving-child' => SavingChild::class]);
    config(['app.debug' => true]);
});

afterEach(function () {
    ComponentRegistry::reset();
    ElementRegistry::reset();
    foreach ($this->savedElements as $type => $class) {
        ElementRegistry::register($type, $class);
    }
});

it('sets a property to a literal from a press', function () {
    Native::test(HandlerExpressionsScreen::class)
        ->tap('open')
        ->assertSet('open', true)
        ->assertSee('Open yes');
});

it('sets strings, numbers and null', function () {
    Native::test(HandlerExpressionsScreen::class)
        ->tap('tab')->assertSet('tab', 'settings')
        ->tap('count')->assertSet('count', -2.5)
        ->tap('clear')->assertSet('note', null);
});

it('toggles a bool', function () {
    Native::test(HandlerExpressionsScreen::class)
        ->tap('toggle')->assertSet('open', true)
        ->tap('toggle')->assertSet('open', false);
});

it('closes a sheet from its dismiss handler, so it can open again', function () {
    Native::test(HandlerExpressionsScreen::class)
        ->tap('open')->assertSet('open', true)
        ->dismissSheet('sheet')->assertSet('open', false)
        ->tap('open')->assertSet('open', true);
});

it('runs updated hooks for a property a handler sets', function () {
    Native::test(HandlerExpressionsScreen::class)
        ->tap('open')
        ->tap('toggle')
        ->assertSet('hooks', ['open:true', 'open:false']);
});

it('refuses to set a #[Locked] property', function () {
    Native::test(HandlerExpressionsScreen::class)->tap('admin');
})->throws(LockedPropertyException::class);

it('sets a parent property from a child event binding', function () {
    Native::test(HandlerExpressionsScreen::class)
        ->tap('child-save')
        ->assertSet('saved', true);
});

it('throws for a handler that cannot run while debugging', function (string $broken, string $message) {
    $screen = Native::test(HandlerExpressionsScreen::class)->set('broken', $broken);

    expect(fn () => $screen->tap('broken'))
        ->toThrow(InvalidHandlerException::class, $message);
})->with([
    'unknown property' => ['property', 'sets [$missing], which is not a public property'],
    'toggle on a string' => ['toggle', 'toggles [$label], which holds string, not a bool'],
    'unknown method' => ['method', 'names no method noSuchMethod()'],
    'unsupported expression' => ['expression', 'is not an expression handlers support'],
]);

it('logs a handler that cannot run and changes nothing without debug', function (string $broken) {
    $log = storage_path('logs/edge-nav.log');
    @unlink($log);
    (fn () => static::$debugLogging = true)->bindTo(null, NativeRouter::class)();

    $screen = Native::test(HandlerExpressionsScreen::class)->set('broken', $broken);
    config(['app.debug' => false]);

    $screen->tap('broken')->assertSet('open', false)->assertSet('label', 'text');

    expect(file_get_contents($log))->toContain('HandlerExpressionsScreen');

    (fn () => static::$debugLogging = null)->bindTo(null, NativeRouter::class)();
    @unlink($log);
})->with(['property', 'toggle', 'method', 'expression']);
