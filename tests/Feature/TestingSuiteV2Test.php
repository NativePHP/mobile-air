<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\PestExpectations;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\ChromeColumnLayout;
use Tests\Fixtures\Edge\ChromeScreen;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;
use Tests\Fixtures\Edge\GateScreen;
use Tests\Fixtures\Edge\HiddenNavOptionsScreen;
use Tests\Fixtures\Edge\HiddenNavScreen;
use Tests\Fixtures\Edge\HiddenTabScreen;
use Tests\Fixtures\Edge\PingReceived;
use Tests\Fixtures\Edge\PlatformScreen;
use Tests\Fixtures\Edge\PollScreen;
use Tests\Fixtures\Edge\SearchableScreen;

beforeEach(function () {
    NativeRouter::clearRoutes();
    app('view')->addLocation(__DIR__.'/../Fixtures/views');
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

// ── ref targeting ───────────────────────────────────

it('taps elements by ref', function () {
    Native::test(CounterScreen::class)
        ->tap('increment-btn')
        ->assertSet('count', 1);
});

it('fires input events by ref', function () {
    Native::test(CounterScreen::class)
        ->input('query-input', 'via-ref')
        ->assertSet('query', 'via-ref')
        ->assertSet('lastHook', 'query:via-ref');
});

it('emits the ref on the wire node', function () {
    Native::test(CounterScreen::class)
        ->assertElement('button', fn (array $n) => ($n['ref'] ?? null) === 'increment-btn');
});

it('parses a blade ref attribute', function () {
    Native::test(PlatformScreen::class)
        ->assertElement('text', fn (array $n) => ($n['ref'] ?? null) === 'platform-label');
});

// ── Flow stack ──────────────────────────────────────

it('keeps the previous screen alive and resumes it on goBack', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    $counter = Native::test(CounterScreen::class)
        ->tap('Increment')
        ->tap('Increment')
        ->tap('Open detail');

    $detail = $counter->follow()
        ->assertScreen(DetailScreen::class)
        ->assertSee('Detail 7 from counter');

    $detail->goBack()
        ->assertScreen(CounterScreen::class)
        ->assertSet('count', 2)      // state preserved across the push/pop
        ->assertSet('resumes', 1)    // onResume() fired exactly once
        ->assertSee('Count: 2');
});

it('can keep interacting after resuming', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    Native::test(CounterScreen::class)
        ->tap('Open detail')
        ->follow()
        ->goBack()
        ->tap('Increment')
        ->assertSet('count', 1);
});

it('refuses interaction with a suspended screen', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    $counter = Native::test(CounterScreen::class)->tap('Open detail');
    $counter->follow();

    $counter->tap('Increment');
})->throws(AssertionFailedError::class, 'suspended');

it('drops replaced screens out of the flow stack', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    $detail = Native::test(GateScreen::class)
        ->follow()
        ->assertScreen(DetailScreen::class);

    // GateScreen was REPLACED, so there is nothing to go back to.
    $detail->call('goBack');
    expect(fn () => $detail->goBack())->toThrow(AssertionFailedError::class);
});

// ── Polls ───────────────────────────────────────────

it('fires all poll methods on demand', function () {
    Native::test(PollScreen::class)
        ->firePolls()
        ->assertSet('ticks', 1)
        ->assertSet('slowTicks', 1)
        ->assertSee('Ticks: 1');
});

it('fires a single poll method by name', function () {
    Native::test(PollScreen::class)
        ->firePoll('tick')
        ->firePoll('tick')
        ->assertSet('ticks', 2)
        ->assertSet('slowTicks', 0);
});

it('rejects unknown poll methods', function () {
    Native::test(PollScreen::class)->firePoll('nonexistent');
})->throws(AssertionFailedError::class);

// ── Search ──────────────────────────────────────────

it('drives dynamic search and stages results', function () {
    $screen = Native::test(SearchableScreen::class)->search('alpha');

    expect($screen->searchResults())->toBe([
        ['title' => 'Alpha release', 'url' => '/'],
    ]);
});

it('refuses search on screens without onSearchQuery', function () {
    Native::test(CounterScreen::class)->search('anything');
})->throws(AssertionFailedError::class);

// ── Platform variants ───────────────────────────────

it('applies ios tailwind variants on ios', function () {
    Native::test(PlatformScreen::class, platform: 'ios')
        ->assertElement('column', fn (array $n) => ($n['style']['bg_color'] ?? null) === '#EF4444');
});

it('applies android tailwind variants on android', function () {
    Native::test(PlatformScreen::class, platform: 'android')
        ->assertElement('column', fn (array $n) => ($n['style']['bg_color'] ?? null) === '#3B82F6');
});

it('drops platform variants when no platform is set', function () {
    Native::test(PlatformScreen::class)
        ->assertElement('column', fn (array $n) => ($n['style']['bg_color'] ?? null) === '#FFFFFF');
});

// ── Render-count guards ─────────────────────────────

it('counts frames and detects re-renders', function () {
    $screen = Native::test(CounterScreen::class)
        ->assertRenderCount(1)
        ->tap('Increment')
        ->assertRerendered()
        ->assertRenderCount(2);

    expect($screen->renderCount())->toBe(2);
});

it('detects that navigation skips the re-render', function () {
    Native::test(CounterScreen::class)
        ->tap('Open detail')
        ->assertNotRerendered();
});

// ── Bridge call assertions ──────────────────────────

it('asserts call counts and relative order', function () {
    Native::fakeBridge()->respondTo('Geolocation.GetCurrentPosition', ['latitude' => 1.0]);

    Native::test(CounterScreen::class)
        ->call('locate')
        ->call('locate')
        ->assertNativeCalledTimes('Geolocation.GetCurrentPosition', 2)
        ->assertNativeCallOrder(['Geolocation.GetCurrentPosition', 'Geolocation.GetCurrentPosition']);
});

it('fails when the call order is wrong', function () {
    Native::test(CounterScreen::class)
        ->call('locate')
        ->assertNativeCallOrder(['Dialog.Toast', 'Geolocation.GetCurrentPosition']);
})->throws(AssertionFailedError::class);

// ── Awaiting-event assertions ───────────────────────

it('asserts a fluent callback is pending, then consumed', function () {
    $screen = Native::test(CounterScreen::class)
        ->assertNotAwaitingNativeEvent(PingReceived::class)
        ->call('awaitPing')
        ->assertAwaitingNativeEvent(PingReceived::class)
        ->emitNative(PingReceived::class, ['message' => 'yo', 'id' => 'ping-capture'])
        ->assertNotAwaitingNativeEvent(PingReceived::class);

    // Both the one-shot callback and the #[On] listener fired.
    expect($screen->get('pings'))->toBe(['cb:yo', 'yo']);
});

// ── Chrome assertions ───────────────────────────────

it('asserts nav title and tabs through native chrome', function () {
    Native::test(ChromeScreen::class, layout: ChromeTabsLayout::class)
        ->assertHasTabBar()
        ->assertNavTitle('Chrome Demo')
        ->assertHasTab('Home')
        ->assertHasTab('Detail')
        ->assertTabActive('Home')
        ->assertTabBarVisible();
});

it('asserts per-screen tab bar hiding', function () {
    Native::test(HiddenTabScreen::class, layout: ChromeTabsLayout::class)
        ->assertNavTitle('Pushed Detail')
        ->assertTabBarHidden();
});

it('asserts per-screen nav bar hiding on native chrome', function () {
    Native::test(HiddenNavScreen::class, layout: ChromeTabsLayout::class)
        ->assertNavBarHidden()
        ->assertTabBarVisible();

    Native::test(ChromeScreen::class, layout: ChromeTabsLayout::class)
        ->assertNavBarVisible();
});

it('hides the nav bar via the navigationOptions builder', function () {
    Native::test(HiddenNavOptionsScreen::class, layout: ChromeTabsLayout::class)
        ->assertNavBarHidden();
});

it('drops the top bar entirely on the custom-Column chrome path', function () {
    Native::test(HiddenNavScreen::class, layout: ChromeColumnLayout::class)
        ->assertNavBarHidden();

    Native::test(ChromeScreen::class, layout: ChromeColumnLayout::class)
        ->assertNavBarVisible()
        ->assertNavTitle('Chrome Demo');
});

it('fails nav title assertions helpfully', function () {
    Native::test(CounterScreen::class)->assertNavTitle('Nope');
})->throws(AssertionFailedError::class);

// ── Snapshots ───────────────────────────────────────

it('matches the wire tree snapshot', function () {
    Native::test(CounterScreen::class)->assertMatchesSnapshot();

    // Re-render and compare again — normalization keeps it stable.
    Native::test(CounterScreen::class)
        ->tap('Increment')
        ->tap('Increment')
        ->assertMatchesSnapshot('after-two-taps');
});

// ── dd() capture ────────────────────────────────────

it('turns a component dd() into a readable failure', function () {
    Native::test(CounterScreen::class)->call('boom');
})->throws(AssertionFailedError::class, 'kaboom');

// ── Pest expectations ───────────────────────────────

it('composes with expect() chains', function () {
    PestExpectations::register();

    expect(Native::test(CounterScreen::class))
        ->toSee('Count: 0')
        ->toNotSee('Feature enabled')
        ->toHaveSet('count', 0)
        ->toHaveElement('button')
        ->toBeOnScreen(CounterScreen::class);

    expect(Native::test(CounterScreen::class)->tap('Open detail'))
        ->toHaveNavigatedTo('/detail/7');
});

// ── make:native-test generator ──────────────────────

it('scaffolds a test file for a component', function () {
    $path = base_path('tests/Feature/CounterScreenTest.php');
    @unlink($path);

    $this->artisan('native:make-test', ['name' => CounterScreen::class])
        ->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)->toContain('Native::test(CounterScreen::class)')
        ->and($contents)->toContain('use Tests\Fixtures\Edge\CounterScreen;');

    @unlink($path);
});

it('refuses to overwrite an existing test without --force', function () {
    $path = base_path('tests/Feature/CounterScreenTest.php');
    file_put_contents($path, '<?php // existing');

    $this->artisan('native:make-test', ['name' => 'CounterScreen'])
        ->assertExitCode(1);

    expect(file_get_contents($path))->toBe('<?php // existing');

    @unlink($path);
});
