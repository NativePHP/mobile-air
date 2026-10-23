<?php

use Native\Mobile\Edge\TreeObservers;
use Native\Mobile\Testing\TreeSpy;

afterEach(fn () => TreeObservers::reset());

it('is a no-op with no observers registered', function () {
    expect(TreeObservers::any())->toBeFalse();

    // Broadcasts with nobody listening must not error.
    TreeObservers::tree(['id' => 1, 'type' => 'column'], '/');
    TreeObservers::event(['type' => 1, 'callback_id' => 5], 'increment');
    TreeObservers::nav(['redirect' => '/next']);

    expect(TreeObservers::any())->toBeFalse();
});

it('fans every frame kind out to an attached spy', function () {
    $spy = TreeSpy::attach();

    expect(TreeObservers::any())->toBeTrue();

    TreeObservers::tree(['id' => 1, 'type' => 'column'], '/home');
    TreeObservers::event(['type' => 1, 'callback_id' => 5], 'increment');
    TreeObservers::nav(['redirect' => '/next']);

    expect($spy->trees)->toHaveCount(1)
        ->and($spy->trees[0]['uri'])->toBe('/home')
        ->and($spy->events[0]['label'])->toBe('increment')
        ->and($spy->navs[0])->toBe(['redirect' => '/next']);
});

it('broadcasts to every registered observer', function () {
    $a = TreeSpy::attach();
    $b = TreeSpy::attach();

    TreeObservers::tree(['id' => 1, 'type' => 'column'], '/');

    expect($a->trees)->toHaveCount(1)
        ->and($b->trees)->toHaveCount(1);
});

it('reset() detaches all observers', function () {
    $spy = TreeSpy::attach();
    TreeObservers::reset();

    TreeObservers::tree(['id' => 1, 'type' => 'column'], '/');

    expect($spy->trees)->toBeEmpty()
        ->and(TreeObservers::any())->toBeFalse();
});
