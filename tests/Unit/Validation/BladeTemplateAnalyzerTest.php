<?php

use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\Elements\Toggle;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Validation\BladeTemplateAnalyzer;
use Native\Mobile\Validation\ValidationResult;

beforeEach(function () {
    $this->analyzer = new BladeTemplateAnalyzer;
    $this->savedElements = ElementRegistry::all();
    $this->savedComponents = ComponentRegistry::all();
});

afterEach(function () {
    ElementRegistry::reset();
    foreach ($this->savedElements as $type => $class) {
        ElementRegistry::register($type, $class);
    }

    ComponentRegistry::reset();
    ComponentRegistry::components($this->savedComponents);
});

/** @return array<string, string> method => type */
function callbackMap(array $callbacks): array
{
    return collect($callbacks)->mapWithKeys(
        fn ($c) => [$c['method'] => $c['type']]
    )->all();
}

it('extracts the canonical tap-family callbacks', function () {
    $callbacks = $this->analyzer->extractCallbacks(<<<'BLADE'
        <native:button @tap="save" />
        <native:pressable @longTap="hold" @doubleTap="zoom">x</native:pressable>
        <native:pressable @tapDown="charge" @tapUp="release">y</native:pressable>
        <native:text-input @change="onChange" @submit="onSubmit" />
        BLADE);

    expect(callbackMap($callbacks))->toBe([
        'save' => 'tap',
        'hold' => 'longTap',
        'zoom' => 'doubleTap',
        'charge' => 'tapDown',
        'release' => 'tapUp',
        'onChange' => 'change',
        'onSubmit' => 'submit',
    ]);
});

it('extracts the @press alias family', function () {
    $callbacks = $this->analyzer->extractCallbacks(<<<'BLADE'
        <native:button @press="save" />
        <native:pressable @longPress="hold" @pressDown="charge" @pressUp="release">x</native:pressable>
        BLADE);

    expect(callbackMap($callbacks))->toBe([
        'save' => 'press',
        'hold' => 'longPress',
        'charge' => 'pressDown',
        'release' => 'pressUp',
    ]);
});

it('extracts the precompiled underscored form', function () {
    $callbacks = $this->analyzer->extractCallbacks('<native:button _press="save" />');

    expect(callbackMap($callbacks))->toBe(['save' => 'press']);
});

it('skips dynamic callback values', function () {
    $callbacks = $this->analyzer->extractCallbacks(
        '<native:button @tap="{{ $method }}" /><native:button @tap="do($id)" /><native:button @tap="live" />'
    );

    expect(callbackMap($callbacks))->toBe(['live' => 'tap']);
});

it('ignores callbacks inside comments', function () {
    $callbacks = $this->analyzer->extractCallbacks(<<<'BLADE'
        {{-- <native:button @tap="old" /> --}}
        <!-- <native:button @tap="older" /> -->
        <native:button @tap="live" />
        BLADE);

    expect(callbackMap($callbacks))->toBe(['live' => 'tap']);
});

/** @return string[] error messages */
function analyzeErrors(BladeTemplateAnalyzer $analyzer, string $blade): array
{
    $result = new ValidationResult;
    $analyzer->analyze('resources/views/native/test.blade.php', $blade, $result);

    return array_column($result->errors(), 'message');
}

it('accepts element types registered by plugins', function () {
    $analyzer = new BladeTemplateAnalyzer(
        ['text' => Text::class, 'outlined_text_input' => TextInput::class, 'list_item' => Text::class],
        [],
    );

    expect(analyzeErrors($analyzer, <<<'BLADE'
        <native:column>
            <native:outlined-text-input @change="update" @submit="save" />
            <native:list-item />
            <native:text>Hi</native:text>
        </native:column>
        BLADE))->toBe([]);
});

it('reads the element registry by default', function () {
    ElementRegistry::register('outlined_text_input', TextInput::class);

    expect(analyzeErrors(new BladeTemplateAnalyzer(unregisteredPluginTypes: []), '<native:outlined-text-input />'))->toBe([]);
});

it('still flags types nobody registered', function () {
    $analyzer = new BladeTemplateAnalyzer(['text' => Text::class], []);

    expect(analyzeErrors($analyzer, '<native:column><native:texxt>Hi</native:texxt></native:column>'))
        ->toBe(["Unknown native element type: 'texxt'"]);
});

it('names the installed but unregistered plugin an unknown type comes from', function () {
    $analyzer = new BladeTemplateAnalyzer([], ['outlined_text_input' => 'nativephp/mobile-ui']);

    expect(analyzeErrors($analyzer, '<native:outlined-text-input />'))->toBe([
        "Unknown native element type: 'outlined-text-input'. It comes from nativephp/mobile-ui, "
        .'which is installed but not registered. Run `php artisan native:plugin:register nativephp/mobile-ui`',
    ]);
});

it('checks @change and @submit against the registered element class', function () {
    $analyzer = new BladeTemplateAnalyzer(['toggle' => Toggle::class, 'text' => Text::class], []);

    expect(analyzeErrors($analyzer, <<<'BLADE'
        <native:toggle @change="flip" @submit="nope" />
        <native:text @change="nope">x</native:text>
        BLADE))->toBe([
        '@submit not supported on <native:toggle>',
        '@change not supported on <native:text>',
    ]);
});

it('skips element checks for registered child components', function () {
    ComponentRegistry::register('todo-row', ValidatorTestChildComponent::class);

    $analyzer = new BladeTemplateAnalyzer([], []);

    expect(analyzeErrors($analyzer, '<native:todo-row :todo="$todo" @change="refresh" />'))->toBe([]);
});

it('only warns about a missing button label when there is nothing else to show', function () {
    $analyzer = new BladeTemplateAnalyzer(['button' => Text::class], []);
    $result = new ValidationResult;

    $analyzer->analyze('x.blade.php', <<<'BLADE'
        <native:button label="Save" />
        <native:button icon="trash" />
        <native:button>Save</native:button>
        <native:button />
        BLADE, $result);

    expect(array_column($result->warnings(), 'line'))->toBe([4]);
});

class ValidatorTestChildComponent extends NativeComponent
{
    public function render(): Element
    {
        return Column::make();
    }
}

it('strips literal arguments from callback names', function () {
    $callbacks = $this->analyzer->extractCallbacks('<native:button @press="delete(1)" /><native:button @press="move(2, 3)" />');

    expect(callbackMap($callbacks))->toBe(['delete' => 'press', 'move' => 'press']);
});
