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
