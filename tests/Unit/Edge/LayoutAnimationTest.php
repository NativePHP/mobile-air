<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\ScrollView;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;

/**
 * `animate-layout` on a container, and the two auto-scroll options that go
 * with it (`auto-scroll-animated`, `auto-scroll-token`). All three ride the
 * props dict, and all three stay off the wire unless they're asked for.
 */
beforeEach(function () {
    NativeElementCollector::reset();
});

afterEach(function () {
    NativeElementCollector::reset();
    NativeTagPrecompiler::setActive(false);
});

function collectScroll(array $attrs): array
{
    NativeElementCollector::open('scroll_view', $attrs);
    NativeElementCollector::leaf('text', ['text' => 'Row']);
    NativeElementCollector::close();

    return NativeElementCollector::collect()->toArray(new CallbackRegistry);
}

it('sets animate_layout from the fluent method', function () {
    $node = Column::make()->animateLayout()->toArray(new CallbackRegistry);

    expect($node['props']['animate_layout'])->toBeTrue();
});

it('leaves animate_layout off when the method is passed false', function () {
    $node = Column::make()->animateLayout()->animateLayout(false)->toArray(new CallbackRegistry);

    expect($node['props'] ?? [])->not->toHaveKey('animate_layout');
});

it('maps the animate-layout attribute on any container', function (string $type, string $attribute, mixed $value) {
    NativeElementCollector::open($type, [$attribute => $value]);
    NativeElementCollector::leaf('text', ['text' => 'Row']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['props']['animate_layout'])->toBeTrue();
})->with(['column', 'row', 'stack', 'scroll_view'])->with([
    ['animate-layout', true],
    ['animateLayout', true],
    ['animate-layout', 'true'],
    ['animate-layout', '1'],
]);

it('leaves animate_layout off for a false value or no attribute', function (array $attrs) {
    NativeElementCollector::open('column', $attrs);
    NativeElementCollector::leaf('text', ['text' => 'Row']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['props'] ?? [])->not->toHaveKey('animate_layout');
})->with([
    'absent' => [[]],
    'false' => [['animate-layout' => false]],
    '"false"' => [['animate-layout' => 'false']],
    '"0"' => [['animate-layout' => '0']],
]);

it('compiles a bare animate-layout attribute to true', function () {
    NativeTagPrecompiler::setActive(true);

    $compiled = (new NativeTagPrecompiler)('<native:column animate-layout><native:text>Row</native:text></native:column>');

    expect($compiled)->toContain("'animate-layout' => true");
});

it('builds the streaming prop for a builtin container', function () {
    expect(NativeElementCollector::buildLayoutAnimationProps(['animate-layout' => true]))->toBe(['animate_layout' => true])
        ->and(NativeElementCollector::buildLayoutAnimationProps([]))->toBe([])
        ->and(NativeElementCollector::buildLayoutAnimationProps(['animate-layout' => 'false']))->toBe([]);
});

it('sets auto_scroll_animated from the attribute and the fluent method', function (string $attribute) {
    expect(collectScroll(['auto-scroll-to' => 2, $attribute => true])['props']['auto_scroll_animated'])->toBeTrue();
    expect(ScrollView::make()->autoScrollAnimated()->toArray(new CallbackRegistry)['props']['auto_scroll_animated'])->toBeTrue();
})->with(['auto-scroll-animated', 'autoScrollAnimated']);

it('leaves auto_scroll_animated off unless asked for', function () {
    expect(collectScroll(['auto-scroll-to' => 2])['props'])->not->toHaveKey('auto_scroll_animated')
        ->and(collectScroll(['auto-scroll-to' => 2, 'auto-scroll-animated' => 'false'])['props'])->not->toHaveKey('auto_scroll_animated')
        ->and(ScrollView::make()->autoScrollAnimated(false)->toArray(new CallbackRegistry)['props'] ?? [])->not->toHaveKey('auto_scroll_animated');
});

it('sends auto_scroll_token as a string', function (string $attribute, string|int $token) {
    $tree = collectScroll(['auto-scroll-to' => 0, $attribute => $token]);

    expect($tree['props']['auto_scroll_token'])->toBe((string) $token)
        ->and($tree['props']['auto_scroll_to'])->toBe(0);
})->with(['auto-scroll-token', 'autoScrollToken'])->with([3, 'commit-c1-2']);

it('leaves auto_scroll_token off when it is null or empty', function (mixed $token) {
    expect(collectScroll(['auto-scroll-to' => 0, 'auto-scroll-token' => $token])['props'])->not->toHaveKey('auto_scroll_token')
        ->and(ScrollView::make()->autoScrollToken($token)->toArray(new CallbackRegistry)['props'] ?? [])->not->toHaveKey('auto_scroll_token');
})->with([null, '']);

it('changes the scroll view node when only the token changes', function () {
    $first = collectScroll(['auto-scroll-to' => 0, 'auto-scroll-token' => 1]);
    NativeElementCollector::reset();
    $second = collectScroll(['auto-scroll-to' => 0, 'auto-scroll-token' => 2]);

    // Same node id, so the scroll view keeps its state, but a new content
    // hash, so the renderer sees a new request rather than a memoised node.
    expect($second['id'])->toBe($first['id'])
        ->and($second['_hash'])->not->toBe($first['_hash']);
});
