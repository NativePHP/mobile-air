<?php

use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\FakeBridge;
use Tests\Fixtures\Edge\ScriptedEventBridge;

/**
 * Partial updates: insertChildren() / removeChildren() send an ops frame in
 * place of a full render. The ops must give native the tree a full render
 * of the same state would, with the same ids, and leave PHP's memo hashes
 * right for the next full frame.
 */
class PartialUpdateOpsScreen extends NativeComponent
{
    /** @var list<int> */
    public array $rows = [3, 2, 1];

    public int $cap = 3;

    public string $title = 'Header';

    public function __construct()
    {
        $this->nativeCallbacks = new CallbackRegistry;
    }

    protected function subtreeMemoEnabled(): bool
    {
        return true;
    }

    public static function row(int $n): Element
    {
        return Column::make(Text::make("Row {$n}"), Text::make('detail'))->key("i{$n}");
    }

    public function render(): Element
    {
        return Column::make(
            Text::make($this->title),
            Column::make(
                Column::make(...array_map(fn (int $n) => self::row($n), $this->rows))->key('items'),
            )->key('body'),
        );
    }

    #[On('rows.prepend')]
    public function prepend(int $n): void
    {
        array_unshift($this->rows, $n);
        $this->insertChildren('items', 0, self::row($n));

        if (count($this->rows) > $this->cap) {
            $this->removeChildren('items', 'i'.array_pop($this->rows));
        }
    }

    #[On('rows.prepend-and-retitle')]
    public function prependAndRetitle(int $n): void
    {
        $this->prepend($n);
        $this->title = "Now {$n}";
        $this->renderInFull();
    }

    #[On('rows.retitle')]
    public function retitle(string $title): void
    {
        $this->title = $title;
    }

    /** @return array<string, mixed> */
    public function fullFrame(): array
    {
        return (new ReflectionMethod(NativeComponent::class, 'memoizedToArray'))->invoke($this, $this->render());
    }

    public function opsTurn(): bool
    {
        return (new ReflectionMethod(NativeComponent::class, 'publishOpsTurn'))->invoke($this);
    }

    /** @return array<int, string> */
    public function hashes(): array
    {
        return (new ReflectionProperty(NativeComponent::class, 'lastNodeHashes'))->getValue($this);
    }
}

/** @return list<array<string, mixed>> */
function opsFrames(): array
{
    return array_values(array_filter(FakeBridge::current()->publishes, fn ($f) => $f['type'] === '__ops'));
}

/** @return array<string, mixed> */
function itemsNode(array $frame): array
{
    return $frame['children'][1]['children'][0];
}

/** Every id in a wire tree, REUSE markers included. */
function wireIds(array $node): array
{
    $ids = [$node['id']];
    foreach ($node['children'] ?? [] as $child) {
        $ids = [...$ids, ...wireIds($child)];
    }

    return $ids;
}

/** A screen that has asked for ops once and published a recorded full frame. */
function recordedScreen(): PartialUpdateOpsScreen
{
    $screen = new PartialUpdateOpsScreen;
    $screen->removeChildren('items');
    $screen->opsTurn(); // nothing recorded yet: dropped, renders in full
    $screen->fullFrame();

    return $screen;
}

beforeEach(fn () => FakeBridge::enable());
afterEach(fn () => FakeBridge::disable());

it('sends an insert and a remove as one ops frame with the ids a full render gives', function () {
    $screen = recordedScreen();
    $screen->prepend(4);

    expect($screen->opsTurn())->toBeTrue();

    $frame = FakeBridge::current()->publishes[0];
    expect($frame['type'])->toBe('__ops')
        ->and($frame['children'])->toHaveCount(2);

    [$insert, $remove] = $frame['children'];
    $items = itemsNode($screen->fullFrame());

    expect($insert['type'])->toBe('__op_insert')
        ->and($insert['props']['parent'])->toBe((string) $items['id'])
        ->and($insert['props']['index'])->toBe(0)
        ->and(wireIds($insert['children'][0]))->toBe(wireIds($items['children'][0]))
        ->and($remove['type'])->toBe('__op_remove')
        ->and($remove['props']['parent'])->toBe((string) $items['id'])
        ->and($remove['props']['ids'])->toBe((string) Element::fnv1a32('/body/items/i1'));
});

it('finds the parent by key under a keyed ancestor, or by key path', function () {
    $screen = recordedScreen();

    expect($screen->nativeKeyPaths('items'))->toBe(['/body/items']);

    $screen->insertChildren('/body/items', 0, PartialUpdateOpsScreen::row(9));
    expect($screen->opsTurn())->toBeTrue()
        ->and(opsFrames()[0]['children'][0]['props']['parent'])->toBe((string) Element::fnv1a32('/body/items'));
});

it('renders in full when the parent key is not live or names two nodes', function () {
    $screen = recordedScreen();

    $screen->insertChildren('nope', 0, PartialUpdateOpsScreen::row(9));
    expect($screen->opsTurn())->toBeFalse();

    // A key used under two parents can't be told apart.
    $screen = recordedScreen();
    $screen->insertChildren('i3', 0, Text::make('a')->key('x'));
    expect($screen->opsTurn())->toBeTrue();
    $screen->insertChildren('i2', 0, Text::make('b')->key('x'));
    expect($screen->opsTurn())->toBeTrue();
    $screen->insertChildren('x', 0, Text::make('c')->key('y'));
    expect($screen->opsTurn())->toBeFalse();
});

it('renders the first turn in full, then records and sends ops', function () {
    $screen = new PartialUpdateOpsScreen;
    $screen->prepend(4);

    expect($screen->opsTurn())->toBeFalse();

    $screen->fullFrame();
    $screen->prepend(5);

    expect($screen->opsTurn())->toBeTrue();
});

it('renders in full when the turn changed anything else', function () {
    $screen = recordedScreen();
    $screen->prepend(4);
    $screen->renderInFull();

    expect($screen->opsTurn())->toBeFalse()
        ->and(FakeBridge::current()->publishes)->toBe([]);

    // The flag is for one turn only.
    $screen->fullFrame();
    $screen->prepend(5);
    expect($screen->opsTurn())->toBeTrue();
});

it('renders in full when native dropped its tree since the recorded frame', function () {
    $screen = recordedScreen();
    FakeBridge::current()->forceFullFrameEpoch++;
    $screen->prepend(4);

    expect($screen->opsTurn())->toBeFalse();
});

it('renders in full on Android, whose reader has no ops yet', function () {
    $screen = recordedScreen();
    $screen->prepend(4);
    putenv('NATIVEPHP_PLATFORM=android');

    try {
        expect($screen->opsTurn())->toBeFalse();
    } finally {
        putenv('NATIVEPHP_PLATFORM');
    }
});

it('drops only the removed subtree from the memo hashes', function () {
    $screen = recordedScreen();
    $before = $screen->hashes();

    $screen->prepend(4); // removes i1
    $screen->opsTurn();

    $gone = [
        Element::fnv1a32('/body/items/i1'),
        Element::fnv1a32('/body/items/i1/0'),
        Element::fnv1a32('/body/items/i1/1'),
    ];
    $kept = Element::fnv1a32('/body/items/i2/0');

    foreach ($gone as $id) {
        expect($before)->toHaveKey($id)
            ->and($screen->hashes())->not->toHaveKey($id);
    }

    expect($screen->hashes()[$kept])->toBe($before[$kept]);
});

it('sends a removed row in full when it comes back, and reuses the rows native kept', function () {
    $screen = recordedScreen();
    $screen->prepend(4); // [4, 3, 2], i1 gone
    $screen->opsTurn();

    // The state goes back to what it was, i1 included.
    $screen->rows = [3, 2, 1];
    $items = itemsNode($screen->fullFrame());
    [$i3, $i2, $i1] = $items['children'];

    expect($i3['flags'] ?? 0)->toBe(1)
        ->and($i2['flags'] ?? 0)->toBe(1)
        ->and($i1['flags'] ?? 0)->toBe(0)
        ->and(array_column($i1['children'], 'flags'))->toBe([]);
});

it('gives an inserted key the parent already shows a fresh subtree', function () {
    $screen = recordedScreen();
    $id = Element::fnv1a32('/body/items/i2/0');
    expect($screen->hashes())->toHaveKey($id);

    $screen->insertChildren('items', 0, PartialUpdateOpsScreen::row(2));
    $screen->opsTurn();

    expect($screen->hashes())->not->toHaveKey($id);
});

it('numbers ops frames so two identical ones are not taken as unchanged', function () {
    $screen = recordedScreen();
    $screen->insertChildren('items', 0, PartialUpdateOpsScreen::row(7));
    $screen->opsTurn();
    $screen->insertChildren('items', 0, PartialUpdateOpsScreen::row(7));
    $screen->opsTurn();

    [$first, $second] = opsFrames();
    expect($second['props']['seq'])->toBe($first['props']['seq'] + 1);
});

it('drops queued ops, the index and the hashes when native asks for a full frame', function () {
    $screen = recordedScreen();
    $screen->prepend(4);

    (new ReflectionMethod(NativeComponent::class, 'dispatchNativeEvent'))
        ->invoke($screen, ['event' => '__edge_full_frame', 'payload' => []]);

    expect($screen->hasPendingOps())->toBeFalse()
        ->and($screen->hashes())->toBe([])
        ->and($screen->nativeKeyPaths('items'))->toBe([]);

    $screen->prepend(5);
    expect($screen->opsTurn())->toBeFalse();
});

it('renders in full for an unkeyed child', function () {
    $screen = recordedScreen();
    $screen->insertChildren('items', 0, Text::make('no key'));

    expect($screen->hasPendingOps())->toBeFalse()
        ->and($screen->opsTurn())->toBeFalse();
});

it('sends patch-only turns of the runloop as ops and anything else in full', function () {
    $prepend = fn (int $n, string $event = 'rows.prepend') => [
        'type' => NativeComponent::EVENT_NATIVE, 'event' => 'native:'.$event, 'payload' => ['n' => $n],
    ];

    $bridge = new ScriptedEventBridge([
        $prepend(4),  // first ops ask: no index yet, so a full frame records one
        $prepend(5),  // ops
        $prepend(6),  // ops
        $prepend(7, 'rows.prepend-and-retitle'), // ops plus a change: full
        ['type' => NativeComponent::EVENT_NATIVE, 'event' => 'native:rows.retitle', 'payload' => ['title' => 'x']],
        $prepend(8),  // ops
    ]);
    app()->instance(FakeBridge::class, $bridge);

    $screen = new PartialUpdateOpsScreen;
    $screen->runLoop();

    expect(array_column($bridge->publishes, 'type'))->toBe([
        'column', 'column', '__ops', '__ops', 'column', 'column', '__ops',
    ]);

    // What native ends with, the last full frame plus its ops, is what a
    // full render of the final state shows.
    // The retitle frame reuses the body, so the rows are the frame before's.
    expect($bridge->publishes[5]['children'][1]['flags'] ?? 0)->toBe(1);
    $lastFull = $bridge->publishes[4];
    $ids = array_map(fn ($c) => $c['id'], itemsNode($lastFull)['children']);
    $ops = $bridge->publishes[6]['children'];
    foreach ($ops as $op) {
        if ($op['type'] === '__op_insert') {
            array_splice($ids, $op['props']['index'], 0, array_column($op['children'], 'id'));
        } else {
            $ids = array_values(array_diff($ids, array_map('intval', explode(',', $op['props']['ids']))));
        }
    }

    expect($screen->rows)->toBe([8, 7, 6])
        ->and($ids)->toBe(array_map(fn ($n) => Element::fnv1a32("/body/items/i{$n}"), [8, 7, 6]));
});
