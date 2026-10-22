<?php

use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\LifecycleHardeningScreen;
use Tests\Fixtures\Edge\NavScreen;
use Tests\Fixtures\Edge\ScriptedEventBridge;
use Tests\Fixtures\Edge\ThrowingPollScreen;

it('binds null when a cleared control sends an empty string to a nullable backed enum', function () {
    Native::test(LifecycleHardeningScreen::class)->call('chooseStatus', '')->assertSet('status', 'null');
});

it('still binds a real enum case and an explicit null', function () {
    Native::test(LifecycleHardeningScreen::class)->call('chooseStatus', 'active')->assertSet('status', 'active');
    Native::test(LifecycleHardeningScreen::class)->call('chooseStatus', null)->assertSet('status', 'null');
});

it('still rejects an invalid case on a nullable enum', function () {
    Native::test(LifecycleHardeningScreen::class)->call('chooseStatus', 'nope');
})->throws(BackedEnumCaseNotFoundException::class);

it('delivers to an #[On] listener whose name collides with the lifecycle guard', function () {
    Native::test(LifecycleHardeningScreen::class)
        ->call('fireListenerEvent')
        ->assertSet('log', ['listener:hello']);
});

it('lets a test call the same navigation methods a template may call', function () {
    Native::test(NavScreen::class)->tap('Direct push')->assertNavigatedTo('/detail/9');
    Native::test(NavScreen::class)->call('navigate', '/detail/9')->assertNavigatedTo('/detail/9');
});

it('still rejects an unknown method from a test', function () {
    Native::test(NavScreen::class)->call('totallyMadeUp');
})->throws(ComponentMethodNotFoundException::class);

it('parks polls once the error screen is up so a throwing poll paints once', function () {
    $bridge = new ScriptedEventBridge([null, null, null, null, ['type' => NativeComponent::EVENT_SHUTDOWN]]);
    app()->instance(FakeBridge::class, $bridge);

    ThrowingPollScreen::$ticks = 0;
    $screen = new ThrowingPollScreen;
    $screen->runLoop();

    expect(ThrowingPollScreen::$ticks)->toBe(1);
});

it('annotates runtime entry points as @internal', function () {
    $r = new ReflectionClass(NativeComponent::class);

    foreach (['mountComponent', 'flushDispatchedEvents', 'dispatchedEvents', '__invokeInteraction'] as $m) {
        expect($r->getMethod($m)->getDocComment())->toContain('@internal');
    }

    expect($r->hasProperty('nativeComponentEventListenerDepth'))->toBeFalse();
});
