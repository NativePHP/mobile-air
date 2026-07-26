<?php

use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Toast;
use Native\Mobile\PendingToast;
use Native\Mobile\Platform;
use Native\Mobile\Testing\FakeBridge;

beforeEach(function () {
    $this->bridge = FakeBridge::enable();
});

afterEach(function () {
    FakeBridge::disable();
    forcePlatform(null);
});

/** Platform caches its bridge probe, so poke the statics directly. */
function forcePlatform(?string $platform): void
{
    $r = new ReflectionClass(Platform::class);
    $r->setStaticPropertyValue('platform', $platform);
    $r->setStaticPropertyValue('detected', true);
}

// ── Building ────────────────────────────────────────

it('defaults to a dismissible toast at the bottom with an automatic duration', function () {
    $payload = (new PendingToast('Saved'))->toArray();

    expect($payload['message'])->toBe('Saved')
        ->and($payload['position'])->toBe(PendingToast::POSITION_BOTTOM)
        ->and($payload['dismissible'])->toBeTrue()
        ->and($payload)->not->toHaveKey('duration')
        ->and($payload)->not->toHaveKey('html')
        ->and($payload['id'])->not->toBeEmpty();
});

it('lets the developer choose the position', function () {
    expect((new PendingToast('Saved'))->top()->toArray()['position'])->toBe('top')
        ->and((new PendingToast('Saved'))->bottom()->toArray()['position'])->toBe('bottom')
        ->and((new PendingToast('Saved'))->position('TOP')->toArray()['position'])->toBe('top');
});

it('rejects unknown positions', function () {
    (new PendingToast('Saved'))->position('middle');
})->throws(InvalidArgumentException::class);

it('accepts durations in seconds', function () {
    expect((new PendingToast('Saved'))->duration(7)->toArray()['duration'])->toBe(7.0)
        ->and((new PendingToast('Saved'))->duration(1.5)->toArray()['duration'])->toBe(1.5);
});

it('understands the short and long duration hints', function () {
    expect((new PendingToast('Saved'))->duration('short')->toArray()['duration'])->toBe(2.0)
        ->and((new PendingToast('Saved'))->duration('long')->toArray()['duration'])->toBe(4.0)
        ->and((new PendingToast('Saved'))->short()->toArray()['duration'])->toBe(2.0)
        ->and((new PendingToast('Saved'))->long()->toArray()['duration'])->toBe(4.0);
});

it('treats a persistent toast as a zero duration', function () {
    expect((new PendingToast('Saved'))->persistent()->toArray()['duration'])->toBe(0.0);
});

it('rejects unknown and negative durations', function () {
    expect(fn () => (new PendingToast('Saved'))->duration('ages'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => (new PendingToast('Saved'))->duration(-1))
        ->toThrow(InvalidArgumentException::class);
});

it('can be made undismissable', function () {
    expect((new PendingToast('Saved'))->dismissible(false)->toArray()['dismissible'])->toBeFalse();
});

it('keeps a developer supplied id', function () {
    $toast = (new PendingToast('Saved'))->id('order-shipped');

    expect($toast->getId())->toBe('order-shipped')
        ->and($toast->toArray()['id'])->toBe('order-shipped');
});

// ── Views ───────────────────────────────────────────

it('wraps an html fragment in a transparent document', function () {
    $html = (new PendingToast)->html('<div class="toast">Saved</div>')->toArray()['html'];

    expect($html)->toContain('<!DOCTYPE html>')
        ->toContain('background:transparent')
        ->toContain('viewport')
        ->toContain('<div class="toast">Saved</div>');
});

it('leaves a full html document alone', function () {
    $document = '<html><body><p>Saved</p></body></html>';

    expect((new PendingToast)->html($document)->toArray()['html'])->toBe($document)
        ->and((new PendingToast)->html("\n  <!DOCTYPE html><html><body>Hi</body></html>")->toArray()['html'])
        ->toContain('<!DOCTYPE html><html><body>Hi</body></html>');
});

it('still wraps a fragment that only talks about html documents', function () {
    $fragment = '<pre>Wrap it in &lt;html&gt;, or write <html> yourself</pre>';

    $html = (new PendingToast)->html($fragment)->toArray()['html'];

    expect($html)->toStartWith('<!DOCTYPE html>')
        ->toContain('background:transparent')
        ->toContain($fragment);
});

it('renders a view as the body of the toast', function () {
    View::addLocation(__DIR__.'/../Fixtures/views');

    $html = (new PendingToast)->view('toast', ['message' => 'Order shipped'])->toArray()['html'];

    expect($html)->toContain('Order shipped')
        ->toContain('<!DOCTYPE html>');
});

it('renders a renderable as the body of the toast', function () {
    $html = (new PendingToast)->view(new HtmlString('<b>Order shipped</b>'))->toArray()['html'];

    expect($html)->toContain('<b>Order shipped</b>');
});

it('refuses to show a toast with no content', function () {
    (new PendingToast)->show();
})->throws(InvalidArgumentException::class);

// ── Bridge ──────────────────────────────────────────

it('sends the toast to the bridge', function () {
    Toast::message('Saved')->top()->duration(3)->id('saved')->show();

    $this->bridge->assertCalled('Toast.Show', function (array $params) {
        return $params['id'] === 'saved'
            && $params['message'] === 'Saved'
            && $params['position'] === 'top'
            && (float) $params['duration'] === 3.0
            && $params['dismissible'] === true;
    });
});

it('shows a toast that was never explicitly shown', function () {
    (function () {
        Toast::message('Saved');
    })();

    $this->bridge->assertCalled('Toast.Show');
});

it('only shows a toast once', function () {
    $toast = Toast::message('Saved');
    $toast->show();
    $toast->show();
    unset($toast);

    $this->bridge->assertCalledTimes('Toast.Show', 1);
});

it('dismisses toasts through the bridge', function () {
    Toast::dismiss('saved');
    Toast::dismissAll();

    $this->bridge
        ->assertCalled('Toast.Dismiss', function (array $params) {
            return $params['id'] === 'saved';
        })
        ->assertCalled('Toast.DismissAll');
});

it('still shows toasts sent through the deprecated Dialog facade', function () {
    Dialog::toast('Saved', 'short');

    $this->bridge->assertCalled('Toast.Show', function (array $params) {
        return $params['message'] === 'Saved'
            && (float) $params['duration'] === 2.0;
    });
});

it('falls back to Dialog.Toast on a known Android device', function () {
    // Toast.* is iOS-only. Under Jump, nativephp_can() answers `true` for
    // everything regardless of the connected device, so without a platform
    // check an Android device silently swallows every toast — including the
    // ones still coming through the deprecated Dialog::toast().
    forcePlatform(Platform::ANDROID);

    Toast::message('Saved')->short()->show();

    $this->bridge->assertCalled('Dialog.Toast', fn (array $p) => $p['message'] === 'Saved');
    $this->bridge->assertNotCalled('Toast.Show');
});

it('gives a persistent toast the longest fallback duration on Android', function () {
    // Android toasts always time out, so 'until dismissed' becomes 'as long
    // as we can' rather than the 2s a zero duration would otherwise map to.
    forcePlatform(Platform::ANDROID);

    Toast::message('Saving')->persistent()->show();
    Toast::message('Saved')->show();

    $this->bridge
        ->assertCalled('Dialog.Toast', function (array $params) {
            return $params['message'] === 'Saving' && $params['duration'] === 'long';
        })
        ->assertCalled('Dialog.Toast', function (array $params) {
            return $params['message'] === 'Saved' && $params['duration'] === 'long';
        });
});

it('skips a view-only toast on Android rather than sending raw markup', function () {
    forcePlatform(Platform::ANDROID);

    Toast::html('<div>Saved</div>')->show();

    $this->bridge->assertNotCalled('Toast.Show');
    $this->bridge->assertNotCalled('Dialog.Toast');
});

it('does not swallow dismissals on an unknown platform', function () {
    // Fail open: only a KNOWN Android device falls back, so tests and any
    // future platform keep the previous behaviour.
    forcePlatform(null);

    Toast::dismiss('saving');

    $this->bridge->assertCalled('Toast.Dismiss');
});
