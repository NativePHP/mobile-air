<?php

use Native\Mobile\Edge\ComponentMethodInvoker;
use Native\Mobile\Edge\Enums\ActionSource;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\Exceptions\DirectlyCallingLifecycleHooksNotAllowedException;
use Native\Mobile\Edge\Exceptions\RemoteActionNotAllowedException;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\RemoteActionScreen;

/**
 * A call whose method name and arguments were chosen outside the app, for
 * example by a server (ActionSource::Remote). It may only reach a public
 * #[NativeAction] method that passes the usual callable guard, with
 * plain-value arguments passed as they are.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

function remoteCall(TestableComponent $screen, string $method, array $args = []): mixed
{
    return $screen->instance()->__invokeInteraction($method, $args, ActionSource::Remote);
}

it('runs a #[NativeAction] method with the args as sent', function () {
    $screen = Native::test(RemoteActionScreen::class);

    remoteCall($screen, 'celebrate', ['ana', 3]);

    expect($screen->get('ran'))->toBe(['celebrate:ana:3'])
        ->and($screen->get('actions'))->toBe([['remote', 'celebrate']]);
});

it('fills optional parameters with their defaults', function () {
    $screen = Native::test(RemoteActionScreen::class);

    remoteCall($screen, 'celebrate', ['ana']);

    expect($screen->get('ran'))->toBe(['celebrate:ana:1']);
});

it('accepts nulls, ints for floats, unions and variadics of plain values', function () {
    $screen = Native::test(RemoteActionScreen::class);

    remoteCall($screen, 'plainValues', [null, 2, 1, ['a'], 'x', ['y' => 1]]);

    expect($screen->get('ran'))->toBe(['plainValues:'.json_encode([null, 2, 1, ['a'], ['x', ['y' => 1]]])]);
});

it('sends a remote call through callAction even when its name is an interaction shortcut', function () {
    $screen = Native::test(RemoteActionScreen::class);

    remoteCall($screen, 'exitToWeb', ['/x']);

    expect($screen->get('actions'))->toBe([['remote', 'exitToWeb']])
        ->and($screen->get('ran'))->toBe(['exitToWeb:/x']);
});

dataset('methods a remote call may not reach', [
    'public but not #[NativeAction]' => ['unmarked', [], RemoteActionNotAllowedException::class],
    'NativeComponent\'s dispatch' => ['dispatch', ['x'], ComponentMethodNotFoundException::class],
    'NativeComponent\'s navigate' => ['navigate', ['/admin'], ComponentMethodNotFoundException::class],
    'a lifecycle hook, even marked' => ['hydrateRange', [], DirectlyCallingLifecycleHooksNotAllowedException::class],
    'mount' => ['mount', [], DirectlyCallingLifecycleHooksNotAllowedException::class],
    'render' => ['render', [], ComponentMethodNotFoundException::class],
    'protected, even marked' => ['hidden', [], ComponentMethodNotFoundException::class],
    'a method that does not exist' => ['nope', [], ComponentMethodNotFoundException::class],
    'a class-typed parameter' => ['withObject', [['a' => 1]], RemoteActionNotAllowedException::class],
    'an optional class-typed parameter, given nothing' => ['withOptionalObject', [], RemoteActionNotAllowedException::class],
    'a callable parameter' => ['withCallable', ['phpinfo'], RemoteActionNotAllowedException::class],
    'a by-reference parameter' => ['byReference', [1], RemoteActionNotAllowedException::class],
    'an argument of the wrong type' => ['celebrate', ['ana', 'lots'], RemoteActionNotAllowedException::class],
    'a string for a float' => ['plainValues', [null, '2', 1, []], RemoteActionNotAllowedException::class],
    'too few arguments' => ['celebrate', [], RemoteActionNotAllowedException::class],
]);

it('refuses a method a remote call may not reach, and runs nothing', function (string $method, array $args, string $exception) {
    $screen = Native::test(RemoteActionScreen::class);

    expect(fn () => remoteCall($screen, $method, $args))->toThrow($exception);

    expect($screen->get('ran'))->toBe([])
        ->and($screen->get('actions'))->toBe([]);
})->with('methods a remote call may not reach');

it('refuses the same methods when asked directly', function (string $method, array $args, string $exception) {
    $screen = Native::test(RemoteActionScreen::class);

    expect(fn () => ComponentMethodInvoker::assertRemoteCallable($screen->instance(), $method, $args))
        ->toThrow($exception);
})->with('methods a remote call may not reach');

it('leaves an unmarked public method callable from a template', function () {
    $screen = Native::test(RemoteActionScreen::class)->call('unmarked');

    expect($screen->get('ran'))->toBe(['unmarked'])
        ->and($screen->get('actions'))->toBe([['interaction', 'unmarked']]);
});

it('only treats the Remote source as remote', function () {
    $remote = collect(ActionSource::cases())
        ->filter(fn (ActionSource $source) => $source->isRemote())
        ->values()
        ->all();

    expect($remote)->toBe([ActionSource::Remote]);
});
