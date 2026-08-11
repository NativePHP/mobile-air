<?php

use Illuminate\Validation\ValidationException;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\LegacyErrorsScreen;
use Tests\Fixtures\Edge\MountValidationScreen;
use Tests\Fixtures\Edge\ValidationChild;
use Tests\Fixtures\Edge\ValidationHostScreen;
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

// ── Review-driven regressions (PR #301 findings) ────

it('eager sync uses attribute rules, never a same-key rules() entry', function () {
    // rules() declares title => min:10; the attribute says min:3. A
    // 5-char sync must PASS (attribute tier only) — the on-demand rule
    // fires solely through validate() (finding 1).
    Native::test(ValidationScreen::class)
        ->input('title-input', 'abcde')
        ->assertDontSee('TITLE-ERR')
        ->input('bio-input', 'ok')
        ->tap('save-btn')
        ->assertSee('TITLE-ERR The title field must be at least 10 characters.');
});

it('keeps a parent validation failure out of the emitting child bag', function () {
    ComponentRegistry::reset();
    ComponentRegistry::components([
        'validation-child' => ValidationChild::class,
    ]);

    try {
        Native::test(ValidationHostScreen::class)
            ->tap('ping-btn') // child emit('saved') → parent parentSave() fails
            ->assertSee('HOST-ERR')      // parent's bag has it (finding 2)
            ->assertDontSee('CHILD-LEAK'); // child's bag does NOT
    } finally {
        ComponentRegistry::reset();
    }
});

it('records a manual throw after a caught validate() in the same dispatch', function () {
    // A stale recorded-state would swallow the second exception (finding 4).
    Native::test(ValidationScreen::class)
        ->call('catchThenRethrow')
        ->assertSee('TITLE-ERR The title field is required.')
        ->assertSee('BIO-ERR Manual bio problem');
});

it('keeps the legacy public-array $errors pattern working', function () {
    // The injected ViewErrorBag is a FALLBACK — a v3-style hand-rolled
    // errors prop still reaches @nativeError untouched (finding 3),
    // including a falsy '0' message (the legacy branch of the falsy
    // fix, pinned separately from the bag branch).
    Native::test(LegacyErrorsScreen::class)
        ->assertSee('Legacy message')
        ->assertElement('text', fn (array $node) => ($node['props']['text'] ?? null) === '0'
            && ($node['props']['color'] ?? null) !== null);
});

it('bubbles a mount()-time validation failure like the device error screen', function () {
    Native::test(MountValidationScreen::class);
})->throws(ValidationException::class);

it('bubbles a render-phase validation failure instead of keeping a stale frame', function () {
    Native::test(ValidationScreen::class)->call('arm');
})->throws(ValidationException::class);

it('leaves untouched wildcard siblings alone in validateOnly', function () {
    // Editing tags.1 must not conjure an error onto tags.0 even though
    // the tags.* rule sweeps the whole array (finding 8).
    Native::test(ValidationScreen::class)
        ->set('tags', ['', ''])
        ->call('checkTag') // validateOnly('tags.1')
        ->assertSee('TAG-ERR')
        ->assertDontSee('TAG0-ERR');
});

// ── Inline-review regressions (second pass) ─────────

it('merges stacked #[Validate] attributes instead of fataling', function () {
    // min:4 + starts_with:@ both fail on 'abc' — BOTH messages must
    // appear, so a last-wins (or first-wins) merge regression can't
    // hide behind a rule that never fails.
    Native::test(ValidationScreen::class)
        ->set('handle', 'abc')
        ->assertSee('HANDLE-ERR The handle field must be at least 4 characters.')
        ->assertSee('HANDLE-ERR The handle field must start with')
        ->set('handle', '@abcd')
        ->assertDontSee('HANDLE-ERR');
});

it('delivers remaining native-event tiers after one listener fails validation', function () {
    // The ->on() closure for 'probe' fails on bio; the #[On] method
    // tier still hears the event AND fails on title. Both tiers'
    // errors must coexist after one delivery — a failing validate()
    // refreshes only its own keys, never the whole bag.
    Native::test(ValidationScreen::class)
        ->emitNative('probe')
        ->assertSet('probed', true)
        ->assertSee('BIO-ERR The bio field is required.')
        ->assertSee('TITLE-ERR The title field is required.');
});

it('returns the target data when only wildcard siblings fail', function () {
    Native::test(ValidationScreen::class)
        ->set('tags', ['', 'ok'])
        ->call('checkTagReturn') // validateOnly('tags.1') — tags.0 fails, target passes
        ->assertSet('tagResult', ['tags' => [1 => 'ok']])
        ->assertDontSee('TAG-ERR'); // and nothing was thrown or recorded
});

it('renders a falsy "0" message through @nativeError', function () {
    Native::test(ValidationScreen::class)
        ->call('zeroError') // addError('bio', '0')
        ->assertElement('text', fn (array $node) => ($node['props']['text'] ?? null) === '0'
            && ($node['props']['color'] ?? null) !== null);
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
    ComponentRegistry::reset();
    ComponentRegistry::components([
        'validation-child' => ValidationChild::class,
    ]);

    try {
        Native::test(ValidationHostScreen::class)
            ->tap('child-save-btn')
            ->assertSee('CHILD-ERR The nickname field is required.')
            ->assertDontSee('HOST-LEAKED'); // parent bag untouched
    } finally {
        ComponentRegistry::reset();
    }
});

it('validates a child prop eagerly on the child input sync', function () {
    ComponentRegistry::reset();
    ComponentRegistry::components([
        'validation-child' => ValidationChild::class,
    ]);

    try {
        Native::test(ValidationHostScreen::class)
            ->input('nickname-input', 'x') // min:2
            ->assertSee('CHILD-ERR The nickname field must be at least 2 characters.')
            ->assertDontSee('HOST-LEAKED');
    } finally {
        ComponentRegistry::reset();
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
