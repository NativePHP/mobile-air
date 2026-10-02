<?php

use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\UnknownElementHint;
use Native\Mobile\Testing\Native;

final class UnknownBareTagScreen extends NativeComponent
{
    public static string $view = '';

    public function render(): View
    {
        return view(self::$view);
    }
}

beforeEach(function () {
    UnknownElementHint::useUnregisteredTypes(['outlined_text_input' => 'nativephp/mobile-ui']);
    if (! is_dir(__DIR__.'/views')) {
        mkdir(__DIR__.'/views', 0755, true);
    }
    app('view')->addLocation(__DIR__.'/views');
});

afterEach(function () {
    UnknownElementHint::useUnregisteredTypes(null);
    foreach (glob(__DIR__.'/views/unknown-bare-tag-*.blade.php') as $file) {
        @unlink($file);
    }
});

function unknownBareTagView(string $name): void
{
    // A fresh file per test, so a compiled copy from another test is never reused.
    $view = 'unknown-bare-tag-'.$name.'-'.uniqid();
    file_put_contents(
        __DIR__."/views/{$view}.blade.php",
        "<column>\n    <text>Todo</text>\n    <outlined-text-input placeholder=\"What needs doing?\" />\n</column>\n"
    );
    UnknownBareTagScreen::$view = $view;
}

it('fails the render with the tag and the package named when debug is on', function () {
    config()->set('app.debug', true);
    unknownBareTagView('debug');

    expect(fn () => Native::test(UnknownBareTagScreen::class))->toThrow(
        Exception::class,
        'It comes from nativephp/mobile-ui, which is installed but not registered.'
    );
});

it('renders without the element and logs it when debug is off', function () {
    config()->set('app.debug', false);
    Log::spy();
    unknownBareTagView('release');

    Native::test(UnknownBareTagScreen::class)->assertSee('Todo');

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'Unknown native element <outlined-text-input> in '))
        ->once();
});
