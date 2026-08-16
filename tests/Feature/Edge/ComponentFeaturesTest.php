<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\Exceptions\DirectlyCallingLifecycleHooksNotAllowedException;
use Native\Mobile\Edge\Exceptions\LockedPropertyException;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ComponentFeaturesScreen;
use Tests\Fixtures\Edge\ParityRecord;

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
