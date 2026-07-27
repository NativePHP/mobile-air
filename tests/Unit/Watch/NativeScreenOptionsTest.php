<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Concerns\InteractsWithWatchTerminal;
use Native\Mobile\Edge\NativeRouter;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;

/**
 * What the `L` key offers the user, and what a chosen pattern turns into.
 */
function screenPicker(): object
{
    return new class
    {
        use InteractsWithWatchTerminal;

        public function options(): array
        {
            return $this->nativeScreenOptions();
        }

        public function fill(?string $pattern): ?string
        {
            return $this->fillScreenParameters($pattern);
        }

        public function intentFor(string $uri): string
        {
            return $this->writeScreenIntentFile($uri);
        }
    };
}

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('offers nothing when the app has no native screens', function () {
    expect(screenPicker()->options())->toBe([]);
});

it('lists every registered native screen with its component', function () {
    Route::native('/settings', CounterScreen::class);
    Route::native('/detail/{id}', DetailScreen::class);

    expect(screenPicker()->options())->toBe([
        '/detail/{id}' => '/detail/{id}   DetailScreen',
        '/settings' => '/settings      CounterScreen',
    ]);
});

it('offers the root screen first, then the rest alphabetically', function () {
    Route::native('/zebra', CounterScreen::class);
    Route::native('/apple', CounterScreen::class);
    Route::native('/', CounterScreen::class);

    expect(array_keys(screenPicker()->options()))->toBe(['/', '/apple', '/zebra']);
});

it('passes a parameterless screen straight through', function () {
    expect(screenPicker()->fill('/settings'))->toBe('/settings');
});

it('has nothing to fill in when the picker was cancelled', function () {
    expect(screenPicker()->fill(null))->toBeNull();
});

// The two ends of the wire: what the watcher pushes has to be exactly what the
// app is prepared to read back out.
it('writes an intent file the app will accept', function () {
    Route::native('/settings', CounterScreen::class);

    $intentFile = screenPicker()->intentFor('/settings');

    copy($intentFile, base_path(NativeRouter::SCREEN_INTENT_PATH));
    @unlink($intentFile);

    expect(NativeRouter::takeScreenIntent())->toBe('/settings');
});
