<?php

use Native\Mobile\Edge\NativeDumpException;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;

/** Everything the overlay published, as one searchable string. */
function overlayJson($screen): string
{
    return json_encode($screen->bridge()->lastPublish());
}

/** Put $count entries on a router stack so isRootScreen() reports deep. */
function attachStack($screen, int $count): void
{
    $router = new NativeRouter;
    $component = $screen->instance();

    \Closure::bind(function () use ($component, $count) {
        /** @var NativeRouter $this */
        for ($i = 0; $i < $count; $i++) {
            $this->stack[] = ['component' => $component, 'uri' => '/screen-'.$i, 'params' => []];
        }
    }, $router, NativeRouter::class)();

    $component->setRouter($router);
}

it('renders the exception overlay with message, class, and a retry action', function () {
    $screen = Native::test(CounterScreen::class);
    $screen->instance()->renderErrorScreen(new RuntimeException('Boom town'));

    $json = overlayJson($screen);

    expect($json)->toContain('Something went wrong')
        ->toContain('Boom town')
        ->toContain('RuntimeException')
        ->toContain('STACK TRACE')
        ->toContain('Try again')
        // Standalone screen (no stack below) — nowhere to go back to.
        ->not->toContain('Go back');
});

it('offers a back action when a screen is below on the stack', function () {
    $screen = Native::test(CounterScreen::class);
    attachStack($screen, 2);

    $screen->instance()->renderErrorScreen(new RuntimeException('Deep failure'));

    expect(overlayJson($screen))->toContain('Go back')->toContain('Try again');
});

it('leaves the broken screen through the overlay back action', function () {
    $screen = Native::test(CounterScreen::class);
    attachStack($screen, 2);

    $screen->instance()->renderErrorScreen(new RuntimeException('Broken'));
    $screen->instance()->__overlayBack();

    expect($screen->navigationIntent()?->type)->toBe(NavigationIntent::BACK);
});

it('recovers in place through the overlay dismiss action', function () {
    $screen = Native::test(CounterScreen::class);

    $screen->instance()->renderErrorScreen(new RuntimeException('Transient'));
    $screen->instance()->__overlayDismiss();

    // The component renders normally again after dismissal.
    $screen->call('increment')->assertSee('Count: 1')->assertNoNavigation();
});

it('renders the dd overlay with the dumps and a continue action', function () {
    $screen = Native::test(CounterScreen::class);

    $screen->instance()->renderDumpScreen(
        new NativeDumpException(['hello-dump', 42], '/app/NativeComponents/Counter.php', 12)
    );

    $json = overlayJson($screen);

    expect($json)->toContain('dd()')
        ->toContain('hello-dump')
        ->toContain('Continue')
        ->not->toContain('Go back');
});

it('offers back on the dd overlay too when the stack is deep', function () {
    $screen = Native::test(CounterScreen::class);
    attachStack($screen, 3);

    $screen->instance()->renderDumpScreen(
        new NativeDumpException(['x'], '/app/Foo.php', 1)
    );

    expect(overlayJson($screen))->toContain('Go back');
});
