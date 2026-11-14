<?php

use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\MemoClosureChild;
use Tests\Fixtures\Edge\MemoHostScreen;
use Tests\Fixtures\Edge\MemoItem;
use Tests\Fixtures\Edge\MemoObjectChild;
use Tests\Fixtures\Edge\MemoRowChild;
use Tests\Fixtures\Edge\MemoTallyChild;
use Tests\Fixtures\Edge\MemoTone;
use Tests\Fixtures\Edge\PlainRowChild;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    ComponentRegistry::reset();
    ComponentRegistry::components([
        'memo-row-child' => MemoRowChild::class,
        'plain-row-child' => PlainRowChild::class,
        'memo-tally-child' => MemoTallyChild::class,
        'memo-closure-child' => MemoClosureChild::class,
        'memo-object-child' => MemoObjectChild::class,
    ]);

    MemoRowChild::$rendered = [];
    MemoClosureChild::$renders = 0;
    MemoObjectChild::$renders = 0;
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

it('re-renders a #[Memo] child whose object prop changed in place, but not one handed an equal fresh object', function () {
    $screen = Native::test(MemoHostScreen::class)
        ->set('item', new MemoItem('First'))->set('withObjectChild', true)->assertSee('Item: First calm');
    $before = MemoObjectChild::$renders;

    $screen->set('item', new MemoItem('First'))->set('title', 'Again')->assertSee('Item: First calm');
    expect(MemoObjectChild::$renders)->toBe($before);

    $screen->instance()->item->label = 'Edited';
    $screen->set('title', 'Once more')->assertSee('Item: Edited calm');
    expect(MemoObjectChild::$renders)->toBe($before + 1);
});

it('keeps a #[Memo] child with an enum prop memoised until the enum changes', function () {
    $screen = Native::test(MemoHostScreen::class)->set('withObjectChild', true)->assertSee('Item: none calm');
    $before = MemoObjectChild::$renders;

    $screen->set('title', 'Again')->set('title', 'Once more');
    expect(MemoObjectChild::$renders)->toBe($before);

    $screen->set('tone', MemoTone::Loud)->assertSee('Item: none loud');
    expect(MemoObjectChild::$renders)->toBe($before + 1);
});

/**
 * Publishes from a run of frames over the memo host with subtree memo on:
 * skipped frames, a changed row, a reorder with a removal, a row's own tap.
 * With $walkEveryRow each kept row forgets its last walk before every frame,
 * so toArray() walks it instead of answering from what it recorded.
 *
 * @return list<array<string, mixed>>
 */
function memoPublishes(bool $walkEveryRow): array
{
    $bridge = Native::fakeBridge();
    $bridge->runtimeFlags = 2;
    $from = count($bridge->publishes);

    $screen = Native::test(MemoHostScreen::class);
    $frames = [
        fn (TestableComponent $s) => $s->set('title', 'One'),
        fn (TestableComponent $s) => $s->set('title', 'Two'),
        fn (TestableComponent $s) => $s->set('rows', ['a' => 'Alpha', 'b' => 'Bravo two', 'c' => 'Charlie']),
        fn (TestableComponent $s) => $s->set('title', 'Three'),
        fn (TestableComponent $s) => $s->set('rows', ['c' => 'Charlie', 'a' => 'Alpha']),
        fn (TestableComponent $s) => $s->tap('tap-a'),
        fn (TestableComponent $s) => $s->set('title', 'Four'),
    ];

    foreach ($frames as $frame) {
        if ($walkEveryRow) {
            forgetLastWalks($screen->instance());
        }

        $frame($screen);
    }

    return array_slice($bridge->publishes, $from);
}

function forgetLastWalks(NativeComponent $component): void
{
    $children = (new ReflectionProperty(NativeComponent::class, 'nativeChildComponents'))->getValue($component);
    $memoRoots = new ReflectionProperty(NativeComponent::class, 'nativeMemoRoots');
    $lastWalk = new ReflectionProperty(Element::class, 'lastWalk');

    foreach ($children as $child) {
        foreach ($memoRoots->getValue($child) ?? [] as $root) {
            $lastWalk->setValue($root, null);
        }

        forgetLastWalks($child);
    }
}

it('publishes the same frames when a kept row answers from its last walk as when it is walked', function () {
    $answered = memoPublishes(walkEveryRow: false);
    $walked = memoPublishes(walkEveryRow: true);

    expect($answered)->toHaveCount(8)
        ->and(json_encode($answered))->toBe(json_encode($walked))
        ->and(substr_count(json_encode(end($answered)), '"flags":1'))->toBeGreaterThan(0);
});
