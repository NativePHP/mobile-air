<?php

use Illuminate\Validation\ValidationException;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\FieldErrors;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\ErrorProbeElement;
use Tests\Fixtures\Edge\PingReceived;
use Tests\Fixtures\Edge\PlainValidatingHostScreen;
use Tests\Fixtures\Edge\SignupScreen;
use Tests\Fixtures\Edge\ValidatingChild;
use Tests\Fixtures\Edge\ValidatingHostScreen;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    ElementRegistry::register('error_probe', ErrorProbeElement::class);
    ComponentRegistry::reset();
    ComponentRegistry::components(['validating-child' => ValidatingChild::class]);
});

afterEach(function () {
    ComponentRegistry::reset();
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

/** The nested ValidatingChild of a ValidatingHostScreen test. */
function validatingChild(TestableComponent $test): ValidatingChild
{
    return (fn () => array_values($this->nativeChildComponents)[0])->call($test->instance());
}

it('keeps messages from listeners, polls and the system back on the screen', function (Closure $handle) {
    $test = Native::test(ValidatingHostScreen::class);

    $handle($test)->assertSee('Host: The title field is required.');

    expect($test->instance()->getErrorBag()->keys())->toBe(['title'])
        ->and(validatingChild($test)->getErrorBag()->isEmpty())->toBeTrue()
        ->and($test->instance()->getNavigationIntent())->toBeNull();
})->with([
    '#[On] native event' => fn (TestableComponent $test) => $test->emitNative(PingReceived::class, ['message' => 'hi']),
    '#[Poll]' => fn (TestableComponent $test) => $test->firePoll('refresh'),
    'onBackPressed()' => fn (TestableComponent $test) => $test->pressBack(),
    'child @event binding' => fn (TestableComponent $test) => $test->tap('Pick'),
]);

it('keeps a parent listener\'s messages in the parent\'s bag when a child emits', function () {
    $test = Native::test(ValidatingHostScreen::class)->tap('Save draft');
    $child = validatingChild($test);

    $test->assertSee('Host: The title field is required.');

    expect($test->instance()->getErrorBag()->keys())->toBe(['title'])
        ->and($child->getErrorBag()->isEmpty())->toBeTrue()
        ->and($child->emitted)->toBeTrue();
});

it('lets a parent without the trait fail as it would without this child', function () {
    $test = Native::test(PlainValidatingHostScreen::class);

    expect(fn () => $test->tap('Save draft'))->toThrow(ValidationException::class, 'The title field is required.')
        ->and(validatingChild($test)->getErrorBag()->isEmpty())->toBeTrue()
        ->and(validatingChild($test)->emitted)->toBeFalse();
});
