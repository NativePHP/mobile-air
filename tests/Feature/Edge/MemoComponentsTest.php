<?php

use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\MemoClosureChild;
use Tests\Fixtures\Edge\MemoHostScreen;
use Tests\Fixtures\Edge\MemoRowChild;
use Tests\Fixtures\Edge\MemoTallyChild;
use Tests\Fixtures\Edge\PlainRowChild;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    ComponentRegistry::reset();
    ComponentRegistry::components([
        'memo-row-child' => MemoRowChild::class,
        'plain-row-child' => PlainRowChild::class,
        'memo-tally-child' => MemoTallyChild::class,
        'memo-closure-child' => MemoClosureChild::class,
    ]);

    MemoRowChild::$rendered = [];
    MemoClosureChild::$renders = 0;
});

afterEach(function () {
    ComponentRegistry::reset();
});

it('renders a #[Memo] child once and skips it while its props and state are unchanged', function () {
    $screen = Native::test(MemoHostScreen::class)
        ->assertSee('Row: Alpha T0')->assertSee('Row: Bravo T0')->assertSee('Row: Charlie T0');

    expect(MemoRowChild::$rendered)->toBe(['Alpha', 'Bravo', 'Charlie']);

    $screen->set('title', 'Changed')->set('title', 'Again')
        ->assertSee('Again')->assertSee('Row: Alpha T0')->assertSee('Row: Charlie T0');

    expect(MemoRowChild::$rendered)->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('puts back the same tree a render would have built', function () {
    $screen = Native::test(MemoHostScreen::class);
    $rendered = json_encode($screen->tree());

    $screen->set('title', 'Host');

    expect(MemoRowChild::$rendered)->toHaveCount(3)
        ->and(json_encode($screen->tree()))->toBe($rendered);
});

it('re-renders only the row whose prop changed', function () {
    $screen = Native::test(MemoHostScreen::class);

    $screen->set('rows', ['a' => 'Alpha', 'b' => 'Bravo two', 'c' => 'Charlie'])
        ->assertSee('Row: Bravo two T0')->assertSee('Row: Alpha T0')->assertDontSee('Row: Bravo T0');

    expect(MemoRowChild::$rendered)->toBe(['Alpha', 'Bravo', 'Charlie', 'Bravo two']);
});

it('re-renders a row when its own state changes, and keeps its callbacks across skipped frames', function () {
    $screen = Native::test(MemoHostScreen::class)
        ->set('title', 'One')->set('title', 'Two');   // two frames that skip every row

    $screen->tap('tap-b')->assertSee('Row: Bravo T1')->assertSee('Row: Alpha T0');
    $screen->tap('tap-b')->assertSee('Row: Bravo T2');

    expect(MemoRowChild::$rendered)->toBe(['Alpha', 'Bravo', 'Charlie', 'Bravo', 'Bravo']);
});

it('re-renders a #[Memo] row when a nested child of it changes state', function () {
    $screen = Native::test(MemoHostScreen::class)->set('title', 'Skipped');

    $screen->tap('hit-a')->assertSee('Hits a 1')
        ->set('title', 'Skipped again')->assertSee('Hits a 1');
    $screen->tap('hit-a')->assertSee('Hits a 2');

    expect(array_count_values(MemoRowChild::$rendered)['Alpha'])->toBe(3);
});

it('renders a child without #[Memo] on every frame', function () {
    $screen = Native::test(MemoHostScreen::class)->set('plain', true);
    $before = count(MemoRowChild::$rendered);

    $screen->set('title', 'Again')->assertSee('Row: Alpha T0');

    expect(count(MemoRowChild::$rendered) - $before)->toBe(3);
});

it('renders a #[Memo] child every frame when its state cannot be fingerprinted', function () {
    $screen = Native::test(MemoHostScreen::class)->set('withClosureChild', true)->assertSee('CLOSURE');
    $before = MemoClosureChild::$renders;

    $screen->set('title', 'Again')->set('title', 'Once more')->assertSee('CLOSURE');

    expect(MemoClosureChild::$renders - $before)->toBe(2);
});

it('drops a #[Memo] child and its elements when it leaves the tree', function () {
    $screen = Native::test(MemoHostScreen::class);

    $screen->set('rows', ['a' => 'Alpha', 'c' => 'Charlie'])->assertDontSee('Row: Bravo')
        ->set('rows', ['a' => 'Alpha', 'b' => 'Bravo', 'c' => 'Charlie'])->assertSee('Row: Bravo T0');

    expect(array_count_values(MemoRowChild::$rendered)['Bravo'])->toBe(2);
});
