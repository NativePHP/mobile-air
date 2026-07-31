<?php

use Native\Mobile\Edge\ComponentRegistry;
use Tests\Fixtures\Edge\BadgeChild;
use Tests\Fixtures\Edge\UserCardChild;

beforeEach(fn () => ComponentRegistry::reset());

afterEach(fn () => ComponentRegistry::reset());

it('registers and resolves a component by kebab tag name', function () {
    ComponentRegistry::register('user-card-child', UserCardChild::class);

    expect(ComponentRegistry::has('user-card-child'))->toBeTrue();
    expect(ComponentRegistry::resolve('user-card-child'))->toBe(UserCardChild::class);
});

it('accepts snake_case lookups for the same tag', function () {
    ComponentRegistry::register('user-card-child', UserCardChild::class);

    expect(ComponentRegistry::has('user_card_child'))->toBeTrue();
    expect(ComponentRegistry::resolve('user_card_child'))->toBe(UserCardChild::class);
});

it('registers many components at once', function () {
    ComponentRegistry::components([
        'user-card-child' => UserCardChild::class,
        'badge-child' => BadgeChild::class,
    ]);

    expect(ComponentRegistry::all())->toBe([
        'user-card-child' => UserCardChild::class,
        'badge-child' => BadgeChild::class,
    ]);
});

it('rejects classes that are not NativeComponent subclasses', function () {
    ComponentRegistry::register('bad', stdClass::class);
})->throws(InvalidArgumentException::class, 'must extend');

it('resolves unregistered names to null', function () {
    expect(ComponentRegistry::resolve('nope'))->toBeNull();
    expect(ComponentRegistry::has('nope'))->toBeFalse();
});

it('resets cleanly', function () {
    ComponentRegistry::register('user-card-child', UserCardChild::class);
    ComponentRegistry::reset();

    expect(ComponentRegistry::all())->toBe([]);
});
