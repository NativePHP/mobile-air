<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Layouts\Builders\Tab;

it('leaves ordinary tabs without a reselect callback', function () {
    $registry = new CallbackRegistry;
    $node = Tab::link('Home', '/home')->toElement()->toArray($registry);

    expect($node['props'])->not->toHaveKey('on_reselect');
    expect($registry->resolve($node['on_press'])['method'])->toBe('__navigate');
});

it('registers a reselect independently of ordinary URL navigation', function () {
    $registry = new CallbackRegistry;
    $node = Tab::link('Home', '/home')->onReselect("backToTop('latest')")
        ->toElement()->toArray($registry);

    expect($registry->resolve($node['props']['on_reselect']))
        ->toBe(['method' => 'backToTop', 'args' => ['latest']]);
    expect($registry->resolve($node['on_press'])['method'])->toBe('__navigate');
    expect($registry->navigations())->toHaveCount(1);
});

it('keeps an explicit press handler alongside a reselect handler', function () {
    $registry = new CallbackRegistry;
    $node = Tab::link('Home', '/home')->press('openHome')->onReselect('backToTop')
        ->toElement()->toArray($registry);

    expect($registry->resolve($node['on_press']))->toBe(['method' => 'openHome', 'args' => []]);
    expect($registry->resolve($node['props']['on_reselect']))->toBe(['method' => 'backToTop', 'args' => []]);
});

it('accepts a reselect handler on an inline bottom navigation item', function () {
    $registry = new CallbackRegistry;
    $item = BottomNavItem::make();
    $item->applyAttributes(['label' => 'Home', 'url' => '/home', 'on-reselect' => 'backToTop']);

    $node = $item->toArray($registry);

    expect($registry->resolve($node['props']['on_reselect']))->toBe(['method' => 'backToTop', 'args' => []]);
    expect($registry->resolve($node['on_press'])['method'])->toBe('__navigate');
});
