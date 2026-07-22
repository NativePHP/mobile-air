<?php

use Native\Mobile\Validation\BladeTemplateAnalyzer;

beforeEach(function () {
    $this->analyzer = new BladeTemplateAnalyzer;
});

/** @return array<string, string> method => type */
function callbackMap(array $callbacks): array
{
    return collect($callbacks)->mapWithKeys(
        fn ($c) => [$c['method'] => $c['type']]
    )->all();
}

it('extracts the canonical press-family callbacks', function () {
    $callbacks = $this->analyzer->extractCallbacks(<<<'BLADE'
        <native:button @press="save" />
        <native:pressable @longPress="hold" @doubleTap="zoom">x</native:pressable>
        <native:pressable @pressDown="charge" @pressUp="release">y</native:pressable>
        <native:text-input @change="onChange" @submit="onSubmit" />
        BLADE);

    expect(callbackMap($callbacks))->toBe([
        'save' => 'press',
        'hold' => 'longPress',
        'zoom' => 'doubleTap',
        'charge' => 'pressDown',
        'release' => 'pressUp',
        'onChange' => 'change',
        'onSubmit' => 'submit',
    ]);
});

it('extracts the @tap alias family', function () {
    $callbacks = $this->analyzer->extractCallbacks(<<<'BLADE'
        <native:button @tap="save" />
        <native:pressable @longTap="hold" @tapDown="charge" @tapUp="release">x</native:pressable>
        BLADE);

    expect(callbackMap($callbacks))->toBe([
        'save' => 'tap',
        'hold' => 'longTap',
        'charge' => 'tapDown',
        'release' => 'tapUp',
    ]);
});

it('extracts the precompiled underscored form', function () {
    $callbacks = $this->analyzer->extractCallbacks('<native:button _press="save" />');

    expect(callbackMap($callbacks))->toBe(['save' => 'press']);
});

it('skips dynamic callback values', function () {
    $callbacks = $this->analyzer->extractCallbacks(
        '<native:button @press="{{ $method }}" /><native:button @tap="do($id)" /><native:button @tap="live" />'
    );

    expect(callbackMap($callbacks))->toBe(['live' => 'tap']);
});

it('ignores callbacks inside comments', function () {
    $callbacks = $this->analyzer->extractCallbacks(<<<'BLADE'
        {{-- <native:button @press="old" /> --}}
        <!-- <native:button @tap="older" /> -->
        <native:button @press="live" />
        BLADE);

    expect(callbackMap($callbacks))->toBe(['live' => 'press']);
});
