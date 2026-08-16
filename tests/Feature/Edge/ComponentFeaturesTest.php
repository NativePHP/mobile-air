<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\Exceptions\DirectlyCallingLifecycleHooksNotAllowedException;
use Native\Mobile\Edge\Exceptions\LockedPropertyException;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ComponentFeaturesScreen;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\InteractionFailureScreen;
use Tests\Fixtures\Edge\ParityPureStatus;
use Tests\Fixtures\Edge\ParityRecord;
use Tests\Fixtures\Edge\RouterBindingFailureScreen;
use Tests\Fixtures\Edge\ScriptedEventBridge;

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('injects services and implicitly binds models and enums into component actions', function () {
    Native::test(ComponentFeaturesScreen::class)
        ->call('resolveAction', '42', 'active')
        ->assertSet('injected', '42:container:active');
});

it('throws a model not found exception when an action binding is missing', function () {
    Native::test(ComponentFeaturesScreen::class)->call('resolveAction', 'missing', 'active');
})->throws(ModelNotFoundException::class);

it('throws the route-compatible exception when an action enum case is invalid', function () {
    Native::test(ComponentFeaturesScreen::class)->call('resolveAction', '42', 'invalid');
})->throws(BackedEnumCaseNotFoundException::class);

it('only invokes public userland actions and protects lifecycle hooks', function () {
    expect(fn () => Native::test(ComponentFeaturesScreen::class)->call('secret'))
        ->toThrow(ComponentMethodNotFoundException::class)
        ->and(fn () => Native::test(ComponentFeaturesScreen::class)->call('mount'))
        ->toThrow(DirectlyCallingLifecycleHooksNotAllowedException::class);
});

it('runs component update hooks in order for dotted state paths', function () {
    Native::test(ComponentFeaturesScreen::class)
        ->set('profile.name', 'Caleb')
        ->assertSet('profile.name', 'Caleb')
        ->assertSet('hookLog', [
            'updating:profile.name:Caleb',
            'updatingProfile:name:Caleb',
            'updatingProfileName:name:Caleb',
            'updated:profile.name:Caleb',
            'updatedProfile:name:Caleb',
            'updatedProfileName:name:Caleb',
        ]);
});

it('applies locked protection through the shared state pipeline', function () {
    Native::test(ComponentFeaturesScreen::class)->set('accountId', 99);
})->throws(LockedPropertyException::class);

it('supports renderless attributes and imperative one-shot render skipping', function () {
    Native::test(ComponentFeaturesScreen::class)
        ->assertRenderCount(1)
        ->call('incrementRenderless')
        ->assertSet('count', 1)
        ->assertRenderCount(1)
        ->assertNotRerendered()
        ->call('increment')
        ->assertSet('count', 2)
        ->assertRenderCount(2)
        ->call('incrementAndSkip')
        ->assertSet('count', 3)
        ->assertRenderCount(2)
        ->assertNotRerendered();
});

it('dispatches bubbling, self-only, and targeted component events', function () {
    Native::test(ComponentFeaturesScreen::class)
        ->call('dispatchNormally')
        ->call('dispatchToSelf')
        ->call('dispatchToClass')
        ->assertSet('events', ['10:container', '11:container', '12:container'])
        ->assertDispatched('parity-saved', id: 10)
        ->assertDispatched('parity-saved', fn ($name, $params) => $name === 'parity-saved' && $params['id'] === 10)
        ->assertDispatchedTo(ComponentFeaturesScreen::class, 'parity-saved', id: 12)
        ->assertNotDispatched('never-happened');
});

it('flushes component events before a navigation intent leaves the device interaction', function () {
    $screen = Native::test(ComponentFeaturesScreen::class)
        ->tap('Dispatch then navigate')
        ->assertNavigatedTo('/next');

    expect($screen->instance()->dispatchedEvents())
        ->toContain(['name' => 'parity-saved', 'params' => ['id' => 13]])
        ->and($screen->instance()->events)->toBe(['13:container'])
        ->and(json_encode($screen->bridge()->lastPublish()))->toContain('Events: 13:container');
});

it('does not let a renderless component-event listener suppress its triggering action frame', function () {
    Native::test(ComponentFeaturesScreen::class)
        ->assertRenderCount(1)
        ->call('incrementAndDispatchRenderless')
        ->assertSet('count', 1)
        ->assertSet('renderlessListenerCalls', 1)
        ->assertRenderCount(2)
        ->assertSee('Count: 1');
});

it('preserves an imperative render skip requested inside a component-event listener', function () {
    Native::test(ComponentFeaturesScreen::class)
        ->assertRenderCount(1)
        ->call('incrementAndDispatchImperativeSkip')
        ->assertSet('count', 1)
        ->assertSet('imperativeSkipListenerCalls', 1)
        ->assertRenderCount(1)
        ->assertNotRerendered()
        ->assertSee('Count: 0');
});

it('contains component-event failures raised while handling system back', function () {
    $bridge = new ScriptedEventBridge([
        ['type' => 8],
        ['type' => ComponentFeaturesScreen::EVENT_SHUTDOWN],
    ]);
    app()->instance(FakeBridge::class, $bridge);

    $screen = new InteractionFailureScreen;
    $screen->runLoop();

    expect($screen->hasErrorState())->toBeTrue();
});

it('contains component-event failures raised while flushing due polls', function () {
    $bridge = new ScriptedEventBridge([
        null,
        ['type' => ComponentFeaturesScreen::EVENT_SHUTDOWN],
    ]);
    app()->instance(FakeBridge::class, $bridge);

    $screen = new InteractionFailureScreen;
    $screen->runLoop();

    expect($screen->hasErrorState())->toBeTrue();
});

it('does not attempt implicit binding for pure enums', function () {
    Native::test(ComponentFeaturesScreen::class)
        ->call('resolvePureEnum', ParityPureStatus::Active)
        ->assertSet('pureEnum', 'Active');

    expect(fn () => Native::test(ComponentFeaturesScreen::class)->call('resolvePureEnum', 'active'))
        ->toThrow(TypeError::class);
});

it('uses Illuminate route matching, constraints, optional parameters, and explicit binders', function () {
    Route::bind('parity_record', function (string $value, IlluminateRoute $route) {
        return new ParityRecord($value.'@'.$route->bindingFieldFor('parity_record'));
    });

    Route::native(
        '/parity-route/{parity_record:slug}/{section?}',
        ComponentFeaturesScreen::class,
    )->whereNumber('parity_record');

    $resolved = NativeRouter::resolve('/parity-route/42?tab=details');

    expect($resolved)
        ->not->toBeNull()
        ->and($resolved['params']['parity_record'])->toBeInstanceOf(ParityRecord::class)
        ->and($resolved['params']['parity_record']->id)->toBe('42@slug')
        ->and($resolved['params'])->not->toHaveKey('section')
        ->and($resolved['params'])->not->toHaveKey('tab')
        ->and($resolved['route'])->toBeInstanceOf(IlluminateRoute::class)
        ->and(NativeRouter::resolve('/parity-route/not-a-number'))->toBeNull();
});

it('honors Laravel route prefixes and URL decoding', function () {
    Route::prefix('settings')->group(function () {
        Route::native('/search/{term}', ComponentFeaturesScreen::class);
    });

    $resolved = NativeRouter::resolve('/settings/search/hello%20world');

    expect($resolved['params']['term'])->toBe('hello world')
        ->and(array_keys(NativeRouter::registeredRoutes()))
        ->toContain('/settings/search/{term}');
});

it('implicitly binds typed public properties using custom route keys', function () {
    Route::native('/parity-property/{record:slug}', ComponentFeaturesScreen::class);

    Native::visit('/parity-property/native-php')
        ->assertSet('record.id', 'native-php@slug');
});

it('binds snake-cased route parameters to camel-cased typed properties', function () {
    Route::native('/parity-property-snake/{parity_record:slug}', ComponentFeaturesScreen::class);

    Native::visit('/parity-property-snake/native-php')
        ->assertSet('parityRecord.id', 'native-php@slug');
});

it('coerces compatible scalar route properties and rejects incompatible values', function () {
    Route::native('/pages/{page}', ComponentFeaturesScreen::class);

    Native::visit('/pages/42')->assertSet('page', 42);

    expect(fn () => Native::visit('/pages/not-a-number'))->toThrow(TypeError::class);
});

it('checks whether a route matches without running its model binders', function () {
    Route::native('/binding-check/{record}', ComponentFeaturesScreen::class);

    expect(NativeRouter::isNativeRoute('/binding-check/missing'))->toBeTrue();
});

it('keeps navigation binding failures inside the current screen lifecycle', function (string $intent) {
    Route::native('/binding-failure/{record}', ComponentFeaturesScreen::class);
    NativeRouter::register('/binding-source', RouterBindingFailureScreen::class);
    Native::fakeBridge();

    RouterBindingFailureScreen::$runCount = 0;
    RouterBindingFailureScreen::$intent = $intent;

    expect((new NativeRouter)->start(
        RouterBindingFailureScreen::class,
        uri: '/binding-source',
    ))->toBe('/recovered')
        ->and(RouterBindingFailureScreen::$runCount)->toBe(2);
})->with(['navigate', 'replace']);

it('prefers literal routes and reuses compiled route patterns', function () {
    Route::native('/items/{record}', ComponentFeaturesScreen::class);
    Route::native('/items/create', CounterScreen::class);

    $registered = NativeRouter::registeredRoutes();

    expect($registered['/items/{record}']['route']->getCompiled())->toBeNull()
        ->and($registered['/items/create']['route']->getCompiled())->toBeNull();

    $literal = NativeRouter::resolve('/items/create');

    expect($literal['class'])->toBe(CounterScreen::class)
        ->and($literal['params'])->toBe([])
        ->and($registered['/items/create']['route']->getCompiled())->not->toBeNull()
        ->and($registered['/items/{record}']['route']->getCompiled())->toBeNull();

    $parameterized = NativeRouter::resolve('/items/native-php');

    expect($parameterized['class'])->toBe(ComponentFeaturesScreen::class)
        ->and($parameterized['params']['record'])->toBeInstanceOf(ParityRecord::class)
        ->and($registered['/items/{record}']['route']->getCompiled())->not->toBeNull();
});

it('uses normal binding for non-soft records on routes that allow trashed models', function () {
    Route::native('/records/{record}', ComponentFeaturesScreen::class)->withTrashed();

    $resolved = NativeRouter::resolve('/records/42');

    expect($resolved['params']['record'])
        ->toBeInstanceOf(ParityRecord::class)
        ->id->toBe('42');
});

it('uses soft-deletable child binding for scoped routes that allow trashed models', function () {
    Route::native(
        '/parents/{parent_record}/children/{child_record}',
        ComponentFeaturesScreen::class,
    )->scopeBindings()->withTrashed();

    $resolved = NativeRouter::resolve('/parents/1/children/2');

    expect($resolved['params']['parent_record']->id)->toBe('parent:1')
        ->and($resolved['params']['child_record']->id)->toBe('trashed-child:2');
});
