<?php

use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;

final class FirstTailwindWarningScreen extends NativeComponent
{
    public function render(): View
    {
        return view('tailwind-warning-first');
    }
}

final class SecondTailwindWarningScreen extends NativeComponent
{
    public function render(): View
    {
        return view('tailwind-warning-second');
    }
}

beforeEach(function () {
    $viewPath = __DIR__.'/views';
    if (! is_dir($viewPath)) {
        mkdir($viewPath, 0755, true);
    }
    app('view')->addLocation($viewPath);

    file_put_contents(
        $viewPath.'/tailwind-warning-first.blade.php',
        '<native:column class="p-4 truncate animate-pulse ios:bg-red-500"><native:text class="truncate">First</native:text></native:column>'
    );
    file_put_contents(
        $viewPath.'/tailwind-warning-second.blade.php',
        '<native:column class="animate-pulse truncate ios:bg-red-500"><native:text>Second</native:text></native:column>'
    );

    TailwindParser::clearCache();
});

afterEach(function () {
    TailwindParser::setPlatform(null);
    TailwindParser::clearCache();

    @unlink(__DIR__.'/views/tailwind-warning-first.blade.php');
    @unlink(__DIR__.'/views/tailwind-warning-second.blade.php');
});

it('warns once per view with the unique unsupported classes in debug mode', function () {
    config()->set('app.debug', true);
    Log::spy();

    Native::test(FirstTailwindWarningScreen::class, platform: 'android');
    Native::test(FirstTailwindWarningScreen::class, platform: 'android');
    Native::test(SecondTailwindWarningScreen::class, platform: 'android');

    Log::shouldHaveReceived('warning')
        ->with('NativePHP EDGE dropped unsupported Tailwind classes.', [
            'view' => 'tailwind-warning-first',
            'classes' => ['truncate', 'animate-pulse'],
        ])
        ->once();

    Log::shouldHaveReceived('warning')
        ->with('NativePHP EDGE dropped unsupported Tailwind classes.', [
            'view' => 'tailwind-warning-second',
            'classes' => ['animate-pulse', 'truncate'],
        ])
        ->once();
});

it('does not warn about unsupported classes when debug mode is disabled', function () {
    config()->set('app.debug', false);
    Log::spy();

    Native::test(FirstTailwindWarningScreen::class, platform: 'android');

    Log::shouldNotHaveReceived('warning');
});

it('attributes cached unsupported classes to every view that uses them', function () {
    config()->set('app.debug', true);
    Log::spy();

    TailwindParser::beginViewDiagnostics('cached-tailwind-first');
    TailwindParser::parse('truncate');
    TailwindParser::endViewDiagnostics();

    TailwindParser::beginViewDiagnostics('cached-tailwind-second');
    TailwindParser::parse('truncate');
    TailwindParser::endViewDiagnostics();

    Log::shouldHaveReceived('warning')
        ->with('NativePHP EDGE dropped unsupported Tailwind classes.', [
            'view' => 'cached-tailwind-first',
            'classes' => ['truncate'],
        ])
        ->once();

    Log::shouldHaveReceived('warning')
        ->with('NativePHP EDGE dropped unsupported Tailwind classes.', [
            'view' => 'cached-tailwind-second',
            'classes' => ['truncate'],
        ])
        ->once();
});
