<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Regression: a conditional subtree that unmounts and later remounts with
 * identical content (camera demo's `@if(count($photos))` block after Clear +
 * retake) must re-emit FULL nodes. Stale hashes from the mounted frame would
 * otherwise produce REUSE markers for ids the native previousTree dropped at
 * unmount, silently truncating the remounted subtree.
 */
class SubtreeMemoPruneComponent extends NativeComponent
{
    public bool $mounted = true;

    public function __construct()
    {
        $this->nativeCallbacks = new CallbackRegistry;
    }

    protected function subtreeMemoEnabled(): bool
    {
        return true;
    }

    public function render(): Element
    {
        $children = [Text::make('Header')];

        if ($this->mounted) {
            $children[] = Column::make(
                Text::make('CAPTURED (1)'),
                Text::make('Clear'),
            );
        }

        return Column::make(...$children);
    }

    /** @return array<string, mixed> */
    public function publishFrame(): array
    {
        $method = new ReflectionMethod(NativeComponent::class, 'memoizedToArray');

        return $method->invoke($this, $this->render());
    }
}

/** @param array<string, mixed> $node @return array<int, array<string, mixed>> */
function flattenMemoNodes(array $node): array
{
    $nodes = [$node];
    foreach ($node['children'] ?? [] as $child) {
        $nodes = array_merge($nodes, flattenMemoNodes($child));
    }

    return $nodes;
}

it('re-emits a remounted conditional subtree FULL instead of stale REUSE markers', function () {
    $component = new SubtreeMemoPruneComponent;

    // Frame 1 — subtree mounted, all nodes emitted FULL and hashed.
    $component->publishFrame();

    // Frame 2 — subtree unmounted; the native reader drops those nodes.
    $component->mounted = false;
    $unmountedFrame = $component->publishFrame();
    $liveIds = array_column(flattenMemoNodes($unmountedFrame), 'id');

    // Frame 3 — remounted with identical content. Nodes absent from frame 2
    // must be FULL: a REUSE marker there references an id the native
    // previousTree no longer holds, truncating the subtree.
    $component->mounted = true;
    $frame = $component->publishFrame();

    $staleReuse = array_filter(
        flattenMemoNodes($frame),
        fn (array $node): bool => ($node['flags'] ?? 0) === 1
            && ! in_array($node['id'], $liveIds, true),
    );

    expect($staleReuse)->toBe([]);

    // The remounted texts really are in the frame (not truncated).
    $texts = array_column(
        array_map(fn (array $node): array => $node['props'] ?? [], flattenMemoNodes($frame)),
        'text',
    );
    expect($texts)->toContain('CAPTURED (1)', 'Clear');
});

it('still emits REUSE markers for subtrees that stay mounted and unchanged', function () {
    $component = new SubtreeMemoPruneComponent;

    $component->publishFrame();
    $frame = $component->publishFrame();

    $reused = array_filter(
        flattenMemoNodes($frame),
        fn (array $node): bool => ($node['flags'] ?? 0) === 1,
    );

    expect($reused)->not->toBe([]);
});

class SubtreeMemoRekeyComponent extends SubtreeMemoPruneComponent
{
    /** @var list<string> */
    public array $keys = ['a'];

    public function render(): Element
    {
        return Column::make(
            Text::make('Header'),
            Column::make(...array_map(
                fn (string $key): Element => Column::make(Text::make('Row'))->key($key),
                $this->keys,
            )),
        );
    }
}

/**
 * The tree the native reader builds from a frame: a REUSE marker takes the
 * node with its id from the tree it built last, as both readers do.
 *
 * @param  array<string, mixed>  $frame
 * @param  array<string, mixed>|null  $previous
 * @return array<string, mixed>
 */
function nativeTreeFrom(array $frame, ?array $previous): array
{
    $index = [];
    foreach ($previous === null ? [] : flattenMemoNodes($previous) as $node) {
        $index[$node['id']] = $node;
    }

    $build = function (array $node) use (&$build, $index): array {
        if (($node['flags'] ?? 0) === 1) {
            return $index[$node['id']] ?? throw new RuntimeException("REUSE marker for id {$node['id']}, which the native side doesn't have");
        }

        if (isset($node['children'])) {
            $node['children'] = array_map($build, $node['children']);
        }

        return $node;
    };

    return $build($frame);
}

it('builds the right tree on the native side when a row is re-keyed and its list then changes', function () {
    $component = new SubtreeMemoRekeyComponent;
    $native = nativeTreeFrom($component->publishFrame(), null);

    // The row's key changes, its content doesn't.
    $component->keys = ['b'];
    $native = nativeTreeFrom($component->publishFrame(), $native);

    // The list around it changes, the row doesn't.
    $component->keys = ['b', 'c'];
    $native = nativeTreeFrom($component->publishFrame(), $native);

    $rowIds = fn (array $tree): array => array_column($tree['children'][1]['children'], 'id');

    expect($rowIds($native))->toBe([Element::fnv1a32('/b'), Element::fnv1a32('/c')]);
});
