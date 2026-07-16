<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;
use Tests\Fixtures\Edge\FormScreen;
use Tests\Fixtures\Edge\MarkerScreen;

beforeEach(function () {
    NativeRouter::clearRoutes();
    app('view')->addLocation(__DIR__.'/../Fixtures/views');
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

// ── Long press ──────────────────────────────────────

it('long-presses by method name', function () {
    Native::test(FormScreen::class)
        ->assertSee('Released')
        ->longPress('hold')
        ->assertSet('held', true)
        ->assertSee('Holding');
});

it('long-presses by ref', function () {
    Native::test(FormScreen::class)
        ->longPress('hold-button')
        ->assertSet('held', true);
});

// ── Submit ──────────────────────────────────────────

it('submits an input with explicit text', function () {
    Native::test(FormScreen::class)
        ->submit('submitDraft', 'ship it')
        ->assertSet('submitted', 'ship it')
        ->assertSee('Submitted: ship it');
});

it('submits by ref, falling back to the typed draft', function () {
    Native::test(FormScreen::class)
        ->input('draft-input', 'draft text')
        ->assertSet('draft', 'draft text')
        ->submit('draft-input')
        ->assertSet('submitted', 'draft text');
});

// ── Event payload coercion ──────────────────────────

it('dispatches checkbox changes as booleans', function () {
    Native::test(FormScreen::class)
        ->check('agree-toggle')
        ->assertSet('agreed', true)
        ->check('agree-toggle', false)
        ->assertSet('agreed', false);
});

it('dispatches slider changes as floats', function () {
    Native::test(FormScreen::class)
        ->slide('setVolume', 0.75)
        ->assertSet('volume', 0.75)
        ->assertSee('Volume: 0.75');
});

it('dispatches radio changes as strings', function () {
    Native::test(FormScreen::class)
        ->selectRadio('pickColor', 'crimson')
        ->assertSet('color', 'crimson')
        ->assertSee('Color: crimson');
});

it('dispatches select changes as strings', function () {
    Native::test(FormScreen::class)
        ->select('pickSize', 'large')
        ->assertSet('size', 'large')
        ->assertSee('Size: large');
});

it('dispatches tab changes as integers', function () {
    Native::test(FormScreen::class)
        ->changeTab('switchTab', 2)
        ->assertSet('activeTab', 2)
        ->assertSee('Tab: 2');
});

// ── Sheet dismissal ─────────────────────────────────

it('dismisses a sheet by ref and re-renders without it', function () {
    Native::test(FormScreen::class)
        ->assertElement('bottom_sheet')
        ->dismissSheet('confirm-sheet')
        ->assertSet('sheetOpen', false)
        ->assertMissingElement('bottom_sheet');
});

it('dismisses a sheet by method name', function () {
    Native::test(FormScreen::class)
        ->dismissSheet('closeSheet')
        ->assertSet('sheetOpen', false);
});

// ── fireEvent primitive ─────────────────────────────

it('fires an event at a full callback expression', function () {
    Native::test(CounterScreen::class)
        ->fireEvent('add(5)', TestableComponent::EVENT_PRESS)
        ->assertSet('count', 5);
});

it('lists the registered callbacks when the target is unknown', function () {
    Native::test(CounterScreen::class)
        ->fireEvent('nonexistent', TestableComponent::EVENT_PRESS);
})->throws(AssertionFailedError::class, 'No callback registered');

// ── Property assertions ─────────────────────────────

it('asserts a property does not have a given value', function () {
    Native::test(CounterScreen::class)
        ->assertNotSet('count', 5)
        ->tap('Increment')
        ->assertNotSet('count', 0);
});

it('fails assertScreen on the wrong component class', function () {
    Native::test(CounterScreen::class)->assertScreen(DetailScreen::class);
})->throws(AssertionFailedError::class);

// ── visit() and flow edge cases ─────────────────────

it('passes navigation data through visit', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    Native::visit('/detail/9', ['from' => 'deep-link'])
        ->assertSee('Detail 9 from deep-link');
});

it('fails visiting an unregistered route', function () {
    Native::visit('/nowhere');
})->throws(AssertionFailedError::class, 'No native route registered');

it('refuses to follow when no navigation happened', function () {
    Native::test(CounterScreen::class)->follow();
})->throws(AssertionFailedError::class, 'nothing to follow');

it('refuses to follow a back intent', function () {
    Native::test(DetailScreen::class)
        ->pressBack()
        ->follow();
})->throws(AssertionFailedError::class, 'Cannot follow');

it('fails follow when the destination route is unregistered', function () {
    Native::test(CounterScreen::class)
        ->tap('Open detail')
        ->follow();
})->throws(AssertionFailedError::class, 'no native route is registered');

it('fails goBack on a screen with no flow parent', function () {
    Native::test(DetailScreen::class)
        ->pressBack()
        ->goBack();
})->throws(AssertionFailedError::class, 'No previous screen');

// ── Blade output buffering ──────────────────────────

it('discards blade template whitespace instead of echoing it', function () {
    ob_start();
    Native::test(MarkerScreen::class)->assertSee('Root content');

    expect(ob_get_clean())->toBe('');
});
