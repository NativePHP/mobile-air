<?php

use Illuminate\View\View;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\BottomSheet;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;

/**
 * replace(), navigate() and back() publish the leaving screen one last
 * time. Sheets and modals are presented over the whole window, so any
 * still open in that frame would cover the next screen until its first
 * frame arrives.
 */
class FarewellSheetsScreen extends NativeComponent
{
    public function render(): View
    {
        return view('farewell-sheets-screen');
    }
}

class FarewellSheetsChild extends NativeComponent
{
    public function render(): Element
    {
        return Column::make(
            BottomSheet::make()->setProp('visible', true),
            (new FarewellModal)->setProp('visible', true),
            (new FarewellPluginSheet)->setProp('visible', true),
            (new FarewellPluginPanel)->setProp('visible', true),
        );
    }
}

class FarewellModal extends Element
{
    protected string $type = 'modal';
}

/** A plugin overlay that opts in to closing on navigation. */
class FarewellPluginSheet extends Element
{
    protected string $type = 'acme.sheet';

    public static function closesOnNavigation(): bool
    {
        return true;
    }
}

/** A plugin element with a `visible` prop that is not an overlay. */
class FarewellPluginPanel extends Element
{
    protected string $type = 'acme.panel';
}

/** @return list<bool> */
function overlayVisibility(array $node): array
{
    $visible = in_array($node['type'], ['bottom_sheet', 'modal', 'acme.sheet', 'acme.panel'], true) ? [$node['props']['visible']] : [];

    foreach ($node['children'] ?? [] as $child) {
        $visible = [...$visible, ...overlayVisibility($child)];
    }

    return $visible;
}

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    // `bottom_sheet` normally comes from a UI plugin.
    $this->savedElements = ElementRegistry::all();
    ElementRegistry::register('bottom_sheet', BottomSheet::class);
    ElementRegistry::register('acme.sheet', FarewellPluginSheet::class);
    ElementRegistry::register('acme.panel', FarewellPluginPanel::class);

    ComponentRegistry::reset();
    ComponentRegistry::components(['farewell-sheets-child' => FarewellSheetsChild::class]);
});

afterEach(function () {
    ElementRegistry::reset();
    foreach ($this->savedElements as $type => $class) {
        ElementRegistry::register($type, $class);
    }

    ComponentRegistry::reset();
});

it('closes every sheet, modal and opted-in plugin overlay of the leaving screen in its farewell frame', function (string $method, array $args) {
    $screen = Native::test(FarewellSheetsScreen::class);

    expect(overlayVisibility($screen->tree()))->toBe([true, true, true, true, true]);

    $screen->call($method, ...$args);

    // The plugin panel does not opt in, so it is left as it was.
    expect(overlayVisibility($screen->bridge()->lastPublish()))->toBe([false, false, false, false, true]);
})->with([
    'replace()' => ['replace', ['/next']],
    'navigate()' => ['navigate', ['/next']],
    'back()' => ['back', []],
]);

/**
 * The tree the native reader builds from a frame: a REUSE marker takes the
 * node with its id from the tree before.
 *
 * @param  array<string, mixed>  $frame
 * @param  array<string, mixed>  $previous
 * @return array<string, mixed>
 */
function farewellNativeTree(array $frame, array $previous): array
{
    $held = [];
    $index = function (array $node) use (&$index, &$held): void {
        $held[$node['id']] = $node;
        foreach ($node['children'] ?? [] as $child) {
            $index($child);
        }
    };
    $index($previous);

    $build = function (array $node) use (&$build, $held): array {
        if (($node['flags'] ?? 0) === 1) {
            return $held[$node['id']] ?? throw new RuntimeException("REUSE marker for id {$node['id']}, which the native side doesn't have");
        }

        if (isset($node['children'])) {
            $node['children'] = array_map($build, $node['children']);
        }

        return $node;
    };

    return $build($frame);
}

/** @return list<array<string, mixed>> */
function farewellReuseMarkers(array $node): array
{
    if (($node['flags'] ?? 0) === 1) {
        return [$node];
    }

    return array_merge([], ...array_map(farewellReuseMarkers(...), $node['children'] ?? []));
}

it('sends what the native side already holds as REUSE markers in the farewell frame, and the overlays it closes in full', function (string $method, array $args) {
    FakeBridge::enable()->runtimeFlags = 0x02; // subtree memo, as on a device

    $screen = Native::test(FarewellSheetsScreen::class);
    $before = $screen->bridge()->lastPublish();

    $screen->call($method, ...$args);
    $farewell = $screen->bridge()->lastPublish();

    // The sheet's text didn't change, so it isn't sent again.
    expect(farewellReuseMarkers($farewell))->not->toBe([])
        ->and(overlayVisibility(farewellNativeTree($farewell, $before)))->toBe([false, false, false, false, true]);
})->with([
    'replace()' => ['replace', ['/next']],
    'navigate()' => ['navigate', ['/next']],
    'back()' => ['back', []],
]);

it('sends the farewell frame in full while subtree memo is off', function () {
    $screen = Native::test(FarewellSheetsScreen::class)->call('navigate', '/next');

    expect(farewellReuseMarkers($screen->bridge()->lastPublish()))->toBe([]);
});
