<?php

use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\CaretScreen;

// ── input() with caret/selection ────────────────────

it('delivers text and a collapsed caret through input() with a selection offset', function () {
    Native::test(CaretScreen::class)
        ->input('caret-input', 'hello', 5)
        ->assertSet('draft', 'hello')
        ->assertSet('seen', 'hello')
        ->assertSet('start', 5)
        ->assertSet('end', 5)
        ->assertSee('Caret: 5-5');
});

it('delivers a selection range through input() with distinct start and end', function () {
    Native::test(CaretScreen::class)
        ->input('caret-input', 'hello world', 0, 5)
        ->assertSet('draft', 'hello world')
        ->assertSet('seen', 'hello world')
        ->assertSet('start', 0)
        ->assertSet('end', 5);
});

it('targets the selection-aware input by method name too', function () {
    Native::test(CaretScreen::class)
        ->input('updateDraft', 'typed', 3)
        ->assertSet('draft', 'typed')
        ->assertSet('start', 3)
        ->assertSet('end', 3);
});

it('keeps plain input() behavior unchanged when no offsets are given', function () {
    Native::test(CaretScreen::class)
        ->input('caret-input', 'plain')
        ->assertSet('draft', 'plain')
        ->assertSet('seen', '')
        ->assertSet('start', -1)
        ->assertSet('end', -1);
});

it('skips the selection event when the target carries no @selectionChange', function () {
    Native::test(CaretScreen::class)
        ->input('plain-input', 'typed', 2, 4)
        ->assertSet('draft', 'typed')
        ->assertSet('start', -1)
        ->assertSet('end', -1);
});

// ── moveCaret() ─────────────────────────────────────

it('moves the caret without changing text, defaulting text from the value prop', function () {
    Native::test(CaretScreen::class)
        ->input('caret-input', 'hello')
        ->moveCaret('caret-input', 2)
        ->assertSet('draft', 'hello')
        ->assertSet('seen', 'hello')
        ->assertSet('start', 2)
        ->assertSet('end', 2);
});

it('moveCaret accepts an explicit range and text', function () {
    Native::test(CaretScreen::class)
        ->moveCaret('caret-input', 1, 4, 'abcdef')
        ->assertSet('seen', 'abcdef')
        ->assertSet('start', 1)
        ->assertSet('end', 4);
});

it('delivers bound args before the (text, start, end) triple', function () {
    Native::test(CaretScreen::class)
        ->moveCaret('labelled-input', 3)
        ->assertSet('label', 'field-a')
        ->assertSet('seen', 'preset')
        ->assertSet('start', 3)
        ->assertSet('end', 3);
});

it('counts offsets in Unicode code points, not bytes', function () {
    Native::test(CaretScreen::class)
        ->moveCaret('caret-input', 2, 5, '😀😀abc')
        ->assertSet('seen', '😀😀abc')
        ->assertSet('start', 2)
        ->assertSet('end', 5);
});

it('clamps offsets beyond the text to its code-point length', function () {
    Native::test(CaretScreen::class)
        ->moveCaret('caret-input', 4, 99, '😀ab')
        ->assertSet('seen', '😀ab')
        ->assertSet('start', 3)
        ->assertSet('end', 3);
});

it('fails moveCaret on an element without a selection callback', function () {
    Native::test(CaretScreen::class)->moveCaret('plain-input', 1);
})->throws(AssertionFailedError::class, 'No @selectionChange callback registered for [plain-input]');

// ── Wire-frame decoding (dispatch `text_selection` kind) ──

it('decodes a well-formed packed frame, keeping U+001F inside the text intact', function () {
    // Only the FIRST unit separator is structural — the text may contain more.
    Native::test(CaretScreen::class)
        ->fireEvent('caretMoved', TestableComponent::EVENT_TEXT_CHANGE, ['text' => "2,4\x1Fab\x1Fcd"])
        ->assertSet('seen', "ab\x1Fcd")
        ->assertSet('start', 2)
        ->assertSet('end', 4);
});

it('swaps inverted offsets so start <= end always holds', function () {
    Native::test(CaretScreen::class)
        ->fireEvent('caretMoved', TestableComponent::EVENT_TEXT_CHANGE, ['text' => "4,1\x1Fhello"])
        ->assertSet('seen', 'hello')
        ->assertSet('start', 1)
        ->assertSet('end', 4);
});

it('decodes multibyte text with emoji at code-point offsets', function () {
    Native::test(CaretScreen::class)
        ->fireEvent('caretMoved', TestableComponent::EVENT_TEXT_CHANGE, ['text' => "1,2\x1F😀é"])
        ->assertSet('seen', '😀é')
        ->assertSet('start', 1)
        ->assertSet('end', 2);
});

it('treats a payload without a separator as text with a caret at its end', function () {
    Native::test(CaretScreen::class)
        ->fireEvent('caretMoved', TestableComponent::EVENT_TEXT_CHANGE, ['text' => 'no separator here'])
        ->assertSet('seen', 'no separator here')
        ->assertSet('start', 17)
        ->assertSet('end', 17);
});

it('treats a non-numeric header as part of the text', function () {
    Native::test(CaretScreen::class)
        ->fireEvent('caretMoved', TestableComponent::EVENT_TEXT_CHANGE, ['text' => "x,y\x1Fhello"])
        ->assertSet('seen', "x,y\x1Fhello")
        ->assertSet('start', 9)
        ->assertSet('end', 9);
});

it('treats a negative-offset header as malformed', function () {
    Native::test(CaretScreen::class)
        ->fireEvent('caretMoved', TestableComponent::EVENT_TEXT_CHANGE, ['text' => "-2,3\x1Fhello"])
        ->assertSet('seen', "-2,3\x1Fhello")
        ->assertSet('start', 10)
        ->assertSet('end', 10);
});
