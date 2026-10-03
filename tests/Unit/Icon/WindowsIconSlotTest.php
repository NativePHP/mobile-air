<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Icon;
use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Icon\IosSymbol;
use Native\Mobile\Icon\WindowsSymbol;
use Native\Mobile\Platform;

/**
 * The `windows` slot: Windows names its own icon, beside `ios` and `android`.
 * Fixture enums are inline and named apart from the other icon tests', because
 * Pest loads every test file into one process.
 */
enum WindowsSlotWindows: string implements WindowsSymbol
{
    case Add = 'U+E710';
}

enum WindowsSlotIos: string implements IosSymbol
{
    case Plus = 'plus';
}

enum WindowsSlotAndroid: string implements AndroidSymbol
{
    public function variant(): string
    {
        return 'filled';
    }

    case Add = 'add';
}

afterEach(function () {
    Platform::set(null);
});

function iconNameOn(string $platform, Icon $icon): ?string
{
    Platform::set($platform);

    return $icon->toArray(new CallbackRegistry)['props']['name'] ?? null;
}

it('uses the windows slot on windows', function () {
    $icon = Icon::make(ios: WindowsSlotIos::Plus, android: WindowsSlotAndroid::Add, windows: WindowsSlotWindows::Add);

    expect(iconNameOn('windows', $icon))->toBe('U+E710');
});

it('prefers the windows slot to the shared name', function () {
    expect(iconNameOn('windows', Icon::make('settings', windows: 'Accept')))->toBe('Accept');
});

it('falls back to the shared name, then to the ios name, on windows', function () {
    expect(iconNameOn('windows', Icon::make('settings', ios: WindowsSlotIos::Plus)))->toBe('settings')
        ->and(iconNameOn('windows', Icon::make(ios: WindowsSlotIos::Plus, android: WindowsSlotAndroid::Add)))->toBe('plus');
});

it('sends no material variant on windows', function () {
    Platform::set('windows');

    expect(IconResolver::resolve(null, null, WindowsSlotAndroid::Add, WindowsSlotWindows::Add))
        ->toBe(['icon' => 'U+E710', 'variant' => null]);
});

it('reads the slot from the win and windows attributes', function (string $attribute) {
    $icon = new Icon;
    $icon->applyAttributes([$attribute => WindowsSlotWindows::Add, 'ios' => WindowsSlotIos::Plus]);

    expect(iconNameOn('windows', $icon))->toBe('U+E710');
})->with(['win', 'windows']);

it('leaves the other platforms alone', function () {
    $icon = Icon::make(ios: WindowsSlotIos::Plus, android: WindowsSlotAndroid::Add, windows: WindowsSlotWindows::Add);

    expect(iconNameOn('ios', $icon))->toBe('plus')
        ->and(iconNameOn('android', $icon))->toBe('add');
});

it('keeps the three-argument call working', function () {
    Platform::set('ios');

    expect(IconResolver::resolve('home', WindowsSlotIos::Plus, null)['icon'])->toBe('plus');
});
