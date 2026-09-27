<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Validation\BladeTemplateAnalyzer;
use Native\Mobile\Validation\NativeComponentAnalyzer;

function resolveViewNameFor(string $class): ?string
{
    $analyzer = new NativeComponentAnalyzer(new Filesystem, new BladeTemplateAnalyzer);

    return (fn () => $this->resolveViewName($class))->call($analyzer);
}

it('resolves the view from the view() helper that native:make generates', function () {
    expect(resolveViewNameFor(AnalyzerHelperViewComponent::class))->toBe('todos.index');
});

it('resolves the view from $this->view()', function () {
    expect(resolveViewNameFor(AnalyzerThisViewComponent::class))->toBe('todos');
});

it('infers the view when render() is not overridden', function () {
    expect(resolveViewNameFor(AnalyzerConventionComponent::class))->toBe('analyzer-convention-component');
});

it('gives up on a view outside native/', function () {
    expect(resolveViewNameFor(AnalyzerWebViewComponent::class))->toBeNull();
});

class AnalyzerHelperViewComponent extends NativeComponent
{
    public function render(): View
    {
        return view('native.todos.index', ['todos' => []]);
    }
}

class AnalyzerThisViewComponent extends NativeComponent
{
    public function render(): Element
    {
        return $this->view('todos');
    }
}

class AnalyzerConventionComponent extends NativeComponent {}

class AnalyzerWebViewComponent extends NativeComponent
{
    public function render(): View
    {
        return view('welcome');
    }
}
