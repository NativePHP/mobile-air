<?php

use Native\Mobile\Attributes\Locked;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Exceptions\LockedPropertyException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;

class LockedPropsScreen extends NativeComponent
{
    #[Locked]
    public int $userId = 7;

    public string $name = 'anon';

    public function impersonate(int $id): void
    {
        $this->userId = $id;
    }

    public function render(): Element
    {
        return Column::make(Text::make("User {$this->userId}: {$this->name}"));
    }
}

it('throws when a binding syncs a #[Locked] property', function () {
    Native::test(LockedPropsScreen::class)->set('userId', 999);
})->throws(LockedPropertyException::class, 'userId');

it('leaves the locked value untouched when a sync is rejected', function () {
    $screen = Native::test(LockedPropsScreen::class);

    try {
        $screen->set('userId', 999);
    } catch (LockedPropertyException) {
    }

    expect($screen->instance()->userId)->toBe(7);
});

it('still syncs unlocked properties normally', function () {
    Native::test(LockedPropsScreen::class)
        ->set('name', 'shane')
        ->assertSee('User 7: shane');
});

it('lets component methods assign locked properties freely', function () {
    $screen = Native::test(LockedPropsScreen::class)->call('impersonate', 42);

    expect($screen->instance()->userId)->toBe(42);
});
