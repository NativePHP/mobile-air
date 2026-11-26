<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\NativeElementCollector;

// Small seams a package builds on. Each one leaves core behaviour as it was.

beforeEach(fn () => NativeElementCollector::reset());
afterEach(fn () => NativeElementCollector::reset());

class SeamPackageContainer extends Column
{
    protected string $type = 'package_container';

    public function wireType(): string
    {
        return 'column';
    }
}

class SeamPrefixedRegistry extends CallbackRegistry
{
    protected function parseExpression(string $expression): array
    {
        return ['method' => 'prefixed_'.$expression, 'args' => []];
    }
}

it('sends an element under its wire type and keeps its own type in PHP', function () {
    $element = SeamPackageContainer::make(Text::make('hi'));

    $tree = $element->toArray(new CallbackRegistry);

    expect($element->getType())->toBe('package_container')
        ->and($element->wireType())->toBe('column')
        ->and($tree['type'])->toBe('column');
});

it('uses the element type as the wire type by default', function () {
    expect(Column::make()->wireType())->toBe('column');
});

it('lets a caller save and restore the bound callback registry, including none', function () {
    $registry = new CallbackRegistry;
    NativeElementCollector::setCallbacks($registry);
    expect(NativeElementCollector::callbacks())->toBe($registry);

    NativeElementCollector::setCallbacks(null);
    expect(NativeElementCollector::callbacks())->toBeNull();

    NativeElementCollector::setCallbacks($registry);
    expect(NativeElementCollector::callbacks())->toBe($registry);
});

it('reports the element the last open() pushed, and none once it is closed', function () {
    expect(NativeElementCollector::openElement())->toBeNull();

    NativeElementCollector::open('column', []);
    $open = NativeElementCollector::openElement();
    expect($open)->toBeInstanceOf(Element::class)
        ->and($open->getType())->toBe('column');

    NativeElementCollector::close();
    expect(NativeElementCollector::openElement())->toBeNull();
});

it('reports no owner while nothing is rendering', function () {
    expect(NativeElementCollector::owner())->toBeNull();
});

it('lets a registry subclass read its own expression encoding', function () {
    $registry = new SeamPrefixedRegistry;
    $id = $registry->register('anything');

    expect($registry->resolve($id)['method'])->toBe('prefixed_anything');
});

it('adds prebuilt action and tab elements after the builder ones', function () {
    $action = TopBarAction::make();
    $nav = NavBar::make()->actionElement($action);
    expect($nav->actionElements())->toBe([$action]);

    $item = BottomNavItem::make();
    $tabs = TabBar::make()->tabElement($item);
    expect($tabs->tabElements())->toBe([$item]);
});
