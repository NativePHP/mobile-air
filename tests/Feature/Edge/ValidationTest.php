<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ValidationScreen;

/**
 * Component validation end to end through the real dispatch cycle:
 * validate()/validateOnly() abort handlers via the runGuarded seam,
 * $errors reaches Blade (@error and @nativeError), eager #[Validate]
 * rules fire on native:model sync — and error DISPLAY stays fully
 * explicit (Livewire semantics): a failed validation never touches a
 * bound element's props.
 */
beforeEach(function () {
    NativeRouter::clearRoutes();
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    // text_input ships in the UI plugin; register it like the plugin would
    // so `<native:text-input native:model=…>` compiles (same arrangement
    // as NestedComponentsTest).
    ElementRegistry::register('text_input', TextInput::class);
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

// ── validate() in handlers ──────────────────────────

it('aborts the handler on failed validation and keeps state', function () {
    Native::test(ValidationScreen::class)
        ->tap('save-btn')
        ->assertSet('saved', false)
        ->assertSee('UNSAVED')
        ->assertSee('TITLE-ERR The title field is required.')
        ->assertSee('BIO-ERR The bio field is required.');
});

it('runs the handler to completion once validation passes', function () {
    Native::test(ValidationScreen::class)
        ->input('title-input', 'A proper title')
        ->input('bio-input', 'short')
        ->tap('save-btn')
        ->assertSet('saved', true)
        ->assertSee('SAVED')
        ->assertDontSee('TITLE-ERR')
        ->assertDontSee('BIO-ERR');
});

it('validates only the given rules when validate() gets an inline array', function () {
    // saveInline() validates just `bio` — the empty (invalid) title must
    // not block it once bio is set.
    Native::test(ValidationScreen::class)
        ->input('bio-input', 'hi')
        ->call('saveInline')
        ->assertSet('saved', true);
});

it('harvests rules and messages from a FormRequest class-string', function () {
    Native::test(ValidationScreen::class)
        ->call('saveViaRequest')
        ->assertSet('saved', false)
        ->assertSee('TITLE-ERR The request fixture insists on a title.');
});

it('records author-thrown ValidationException::withMessages and aborts', function () {
    Native::test(ValidationScreen::class)
        ->call('failManually')
        ->assertSee('TITLE-ERR Custom problem');
});

// ── Eager #[Validate] rules on native:model sync ────

it('validates eagerly on sync for #[Validate] props', function () {
    Native::test(ValidationScreen::class)
        ->input('title-input', 'ab') // min:3
        ->assertSet('title', 'ab')   // the sync itself is never rolled back
        ->assertSee('TITLE-ERR The title field must be at least 3 characters.');
});

it('clears the field error once an eager re-validation passes', function () {
    Native::test(ValidationScreen::class)
        ->input('title-input', 'ab')
        ->assertSee('TITLE-ERR')
        ->input('title-input', 'long enough now')
        ->assertDontSee('TITLE-ERR');
});

it('does not run rules()-method rules on sync', function () {
    // bio's rules live in rules() (on-demand tier): typing an over-limit
    // value must not surface an error until a validate() call does.
    Native::test(ValidationScreen::class)
        ->input('bio-input', 'far too long for max five')
        ->assertDontSee('BIO-ERR')
        ->tap('save-btn')
        ->assertSee('BIO-ERR');
});

// ── validateOnly + wildcards ────────────────────────

it('validateOnly matches wildcard rules and scopes the bag to that field', function () {
    Native::test(ValidationScreen::class)
        ->set('tags', ['ok', ''])
        ->call('checkTag') // validateOnly('tags.1') against 'tags.*'
        ->assertSee('TAG-ERR')
        ->assertDontSee('TITLE-ERR'); // untouched fields stay unvalidated
});

// ── Blade error display ─────────────────────────────

it('renders @nativeError from the injected ViewErrorBag', function () {
    Native::test(ValidationScreen::class)
        ->tap('save-btn')
        ->assertElement('text', fn (array $node) => ($node['props']['color'] ?? null) !== null
            && str_contains($node['props']['text'] ?? '', 'The bio field is required.'));
});

// ── No automatic error display (Livewire semantics) ─

it('never injects error props into model-bound elements', function () {
    // Error DISPLAY is the author's explicit call (@error/@nativeError
    // or error/supporting attributes) — a failed validation must leave
    // the bound element's props untouched.
    Native::test(ValidationScreen::class)
        ->tap('save-btn')
        ->assertSee('TITLE-ERR') // the error exists…
        ->assertElement('text_input', fn (array $node) => ($node['ref'] ?? null) === 'title-input'
            && ! isset($node['props']['is_error'])
            && ! isset($node['props']['supporting'])); // …but the element is untouched
});

it('strips the model-prop metadata attribute before elements see it', function () {
    Native::test(ValidationScreen::class)
        ->assertElement('text_input', fn (array $node) => ($node['ref'] ?? null) === 'title-input'
            && ! isset($node['props']['model-prop'])
            && ! isset($node['props']['model_prop']));
});

// ── Child-component scoping ─────────────────────────

it('scopes validation errors to the child component that failed', function () {
    \Native\Mobile\Edge\ComponentRegistry::reset();
    \Native\Mobile\Edge\ComponentRegistry::components([
        'validation-child' => \Tests\Fixtures\Edge\ValidationChild::class,
    ]);

    try {
        Native::test(\Tests\Fixtures\Edge\ValidationHostScreen::class)
            ->tap('child-save-btn')
            ->assertSee('CHILD-ERR The nickname field is required.')
            ->assertDontSee('HOST-LEAKED'); // parent bag untouched
    } finally {
        \Native\Mobile\Edge\ComponentRegistry::reset();
    }
});

it('validates a child prop eagerly on the child input sync', function () {
    \Native\Mobile\Edge\ComponentRegistry::reset();
    \Native\Mobile\Edge\ComponentRegistry::components([
        'validation-child' => \Tests\Fixtures\Edge\ValidationChild::class,
    ]);

    try {
        Native::test(\Tests\Fixtures\Edge\ValidationHostScreen::class)
            ->input('nickname-input', 'x') // min:2
            ->assertSee('CHILD-ERR The nickname field must be at least 2 characters.')
            ->assertDontSee('HOST-LEAKED');
    } finally {
        \Native\Mobile\Edge\ComponentRegistry::reset();
    }
});

it('lets element-resolved props win over setProp extras (merge order)', function () {
    // Generic Element contract: extraProps rank BELOW the subclass's
    // resolveProps in toArray(), so anything an element resolves from
    // its own attributes beats a generic setProp() of the same key.
    $element = new class extends Element
    {
        protected string $type = 'probe';

        protected function resolveProps(CallbackRegistry $registry): array
        {
            return ['supporting' => 'author text'];
        }
    };

    $element->setProp('supporting', 'injected message');
    $element->setProp('is_error', true);

    $nextId = 1;
    $emitted = [];
    $throwaway = [];
    $node = $element->toArray(new CallbackRegistry, $nextId, '', 0, $emitted, $throwaway);

    expect($node['props']['supporting'])->toBe('author text')
        ->and($node['props']['is_error'])->toBeTrue(); // untouched key survives
});
