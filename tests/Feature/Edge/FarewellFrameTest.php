<?php

use Illuminate\View\View;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\BottomSheet;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
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
        );
    }
}

class FarewellModal extends Element
{
    protected string $type = 'modal';
}

/** @return list<bool> */
function sheetAndModalVisibility(array $node): array
{
    $visible = in_array($node['type'], ['bottom_sheet', 'modal'], true) ? [$node['props']['visible']] : [];

    foreach ($node['children'] ?? [] as $child) {
        $visible = [...$visible, ...sheetAndModalVisibility($child)];
    }

    return $visible;
}

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    // `bottom_sheet` normally comes from a UI plugin.
    $this->savedElements = ElementRegistry::all();
    ElementRegistry::register('bottom_sheet', BottomSheet::class);

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

it('closes every sheet and modal of the leaving screen in its farewell frame', function (string $method, array $args) {
    $screen = Native::test(FarewellSheetsScreen::class);

    expect(sheetAndModalVisibility($screen->tree()))->toBe([true, true, true]);

    $screen->call($method, ...$args);

    expect(sheetAndModalVisibility($screen->bridge()->lastPublish()))->toBe([false, false, false]);
})->with([
    'replace()' => ['replace', ['/next']],
    'navigate()' => ['navigate', ['/next']],
    'back()' => ['back', []],
]);
