<?php

use Illuminate\Validation\ValidationException;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\FieldErrors;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\CheckedSignupScreen;
use Tests\Fixtures\Edge\ErrorProbeElement;
use Tests\Fixtures\Edge\SignupScreen;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    ElementRegistry::register('error_probe', ErrorProbeElement::class);
});

/** @return array<int, string|null> error prop of each probe, in tree order */
function probeErrors(TestableComponent $test): array
{
    $errors = [];
    $walk = function (array $node) use (&$walk, &$errors) {
        if (($node['type'] ?? null) === 'error_probe') {
            $errors[] = $node['props']['error'] ?? null;
        }
        foreach ($node['children'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($test->tree());

    return $errors;
}

it('keeps validation messages on the screen instead of failing the interaction', function () {
    $test = Native::test(SignupScreen::class)
        ->tap('Save')
        ->assertSee('Not saved')
        ->assertSee('Blade: The name field is required.');

    expect($test->instance()->getErrorBag()->keys())->toBe(['name', 'email']);
});

it('shows each bound field its own message and never overrides an authored error', function () {
    $test = Native::test(SignupScreen::class)->tap('Save');

    expect(probeErrors($test))->toBe([
        'The name field is required.',            // native:model="name"
        'The email address field is required.',   // native:model="email", custom attribute name
        'The email address field is required.',   // error-for="email"
        'Authored error',                          // authored error wins
    ]);
});

it('clears every message after a successful validate()', function () {
    $test = Native::test(SignupScreen::class)->tap('Save');
    $screen = $test->instance();
    $screen->name = 'Ada';
    $screen->email = 'ada@example.com';

    $test->tap('Save')->assertSee('Saved')->assertDontSee('Blade:');

    expect($screen->getErrorBag()->isEmpty())->toBeTrue()
        ->and(probeErrors($test))->toBe([null, null, null, 'Authored error']);
});

it('validateOnly() replaces only its own field', function () {
    $test = Native::test(SignupScreen::class)->tap('Save');
    $screen = $test->instance();
    $screen->email = 'not-an-email';

    try {
        $screen->validateOnly('email');
    } catch (ValidationException) {
    }

    expect($screen->getErrorBag()->get('email'))->toBe(['The email address field must be a valid email address.'])
        ->and($screen->getErrorBag()->get('name'))->toBe(['The name field is required.']);

    $screen->email = 'ada@example.com';
    $screen->validateOnly('email');

    expect($screen->getErrorBag()->has('email'))->toBeFalse()
        ->and($screen->getErrorBag()->has('name'))->toBeTrue();
});

it('records a hand-thrown ValidationException', function () {
    $test = Native::test(SignupScreen::class)->tap('Reject');

    $test->assertSee('Blade: That name is taken.');
    expect($test->instance()->getErrorBag()->keys())->toBe(['name']);
});

it('has no bag outside a render', function () {
    Native::test(SignupScreen::class)->tap('Save');

    expect(FieldErrors::current())->toBeNull()
        ->and(FieldErrors::for(['error-for' => 'name']))->toBeNull();
});

it('keeps a message thrown from an updated*() hook during native:model sync', function () {
    $test = Native::test(SignupScreen::class)->input('email', 'not-an-email');

    expect($test->instance()->email)->toBe('not-an-email')
        ->and(probeErrors($test)[1])->toBe('The email address field must be a valid email address.')
        ->and(probeErrors($test)[0])->toBeNull();

    $test->input('email', 'ada@example.com');

    expect(probeErrors($test)[1])->toBeNull();
});

it('syncs native:model and then runs @change on the same input, in either order', function () {
    $test = Native::test(CheckedSignupScreen::class)
        ->input('name', 'Ada')              // native:model first
        ->input('nickname', 'ace')          // legacy @model
        ->input('email', 'not-an-email');   // @change first, with an argument

    $screen = $test->instance();

    // updatedEmail() ran, and its message shows under the input bound to email.
    expect($screen->email)->toBe('not-an-email')
        ->and(probeErrors($test))->toBe([null, 'The email address field must be a valid email address.', null]);

    $test->input('email', 'ada@example.com');

    expect($screen->name)->toBe('Ada')
        ->and($screen->nickname)->toBe('ace')
        ->and($screen->email)->toBe('ada@example.com')
        // Each handler got the typed value, after the property had synced.
        ->and($screen->checks)->toBe([
            ['Ada', 'Ada'],
            ['ace', 'ace'],
            [5, 'ada@example.com', 'ada@example.com'],
        ])
        ->and(probeErrors($test))->toBe([null, null, null]);
});

it('finds the bound field of an input that also carries @change', function () {
    expect(FieldErrors::fieldFor(['_change' => "__syncPropertyThen('email', 'check')"]))->toBe('email')
        ->and(FieldErrors::fieldFor(['_change' => "__syncProperty('email')"]))->toBe('email')
        ->and(FieldErrors::fieldFor(['_change' => 'check']))->toBeNull();
});
