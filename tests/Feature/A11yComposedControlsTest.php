<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\GestureArea;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Testing\Native;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\ComposedControlsScreen;
use Tests\Fixtures\Edge\IncompleteComposedScreen;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../Fixtures/views');
});

function composedNode(string $ref): array
{
    $find = function (array $node) use (&$find, $ref): ?array {
        if (($node['props']['ref'] ?? null) === $ref) {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (($found = $find($child)) !== null) {
                return $found;
            }
        }

        return null;
    };

    return $find(Native::test(ComposedControlsScreen::class)->tree())
        ?? throw new RuntimeException("No node with ref [{$ref}]");
}

// ── Hydration from Blade ────────────────────────────

it('hydrates role and checked state on a composed checkbox', function () {
    expect(composedNode('terms')['props'])
        ->toMatchArray(['a11y_role' => 'checkbox', 'a11y_checked' => 'true', 'a11y_label' => 'Accept the terms']);
});

it('hydrates an adjustable value, range and adjust callbacks', function () {
    $props = composedNode('volume')['props'];

    expect($props)->toMatchArray([
        'a11y_role' => 'adjustable',
        'a11y_value' => '40%',
        'a11y_value_now' => 40.0,
        'a11y_value_min' => 0.0,
        'a11y_value_max' => 100.0,
    ])
        ->and($props['on_a11y_increment'])->toBeInt()
        ->and($props['on_a11y_decrement'])->toBeInt()
        ->and($props['on_a11y_increment'])->not->toBe($props['on_a11y_decrement']);
});

it('hydrates selection, error and disabled on a radio group', function () {
    $group = composedNode('priority');

    expect($group['props'])->toMatchArray(['a11y_role' => 'radiogroup', 'a11y_error' => 'Choose a priority.'])
        ->and($group['children'][0]['props'])->toMatchArray(['a11y_role' => 'radio', 'a11y_selected' => true])
        ->and($group['children'][1]['props'])->toMatchArray(['a11y_selected' => false, 'disabled' => true]);
});

it('hydrates state, expanded and custom actions, skipping dividers', function () {
    expect(composedNode('row')['props'])->toMatchArray([
        'a11y_state' => '3 unread',
        'a11y_expanded' => true,
        'a11y_action_labels' => ['Delete'],
    ])
        ->and(composedNode('row')['props']['a11y_action_callbacks'])->toHaveCount(1);
});

it('hydrates live, hidden, modal and pane title on any element', function () {
    expect(composedNode('sync')['props'])->toMatchArray(['a11y_live' => 'polite'])
        ->and(composedNode('star')['props'])->toMatchArray(['a11y_hidden' => true])
        ->and(composedNode('dialog')['props'])->toMatchArray([
            'a11y_modal' => true,
            'a11y_pane_title' => 'Discard draft?',
        ])
        ->and(composedNode('dialog')['props']['on_a11y_dismiss'])->toBeInt();
});

it('accepts the camelCase spellings too', function () {
    $node = Pressable::make(Text::make('Notifications'))->onPress('noop');
    $node->applyA11yAttributes(['a11yRole' => 'switch', 'a11yChecked' => 'false', 'a11ySelected' => 'true']);

    expect($node->toArray(new CallbackRegistry)['props'])
        ->toMatchArray(['a11y_role' => 'switch', 'a11y_checked' => 'false', 'a11y_selected' => true]);
});

it('keeps state props on the node while they are null', function () {
    expect(composedNode('toolbar')['props'])
        ->toMatchArray(['a11y_error' => '', 'a11y_checked' => '', 'a11y_action_labels' => [], 'a11y_action_callbacks' => []])
        ->and(composedNode('status')['props'])->toMatchArray(['a11y_state' => '']);
});

it('reads a bare a11y-live as polite and disabled="disabled" as disabled', function () {
    expect(composedNode('status')['props']['a11y_live'])->toBe('polite')
        ->and(composedNode('draft')['props']['disabled'])->toBeTrue();
});

// ── Fluent API and validation ───────────────────────

it('serializes the fluent API', function () {
    $props = GestureArea::make()
        ->a11yRole('progress')
        ->a11yRange(3, 0, 5)
        ->a11yValue('3 of 5')
        ->a11yChecked('mixed')
        ->toArray(new CallbackRegistry)['props'];

    expect($props)->toMatchArray([
        'a11y_role' => 'progress',
        'a11y_value' => '3 of 5',
        'a11y_value_now' => 3.0,
        'a11y_value_max' => 5.0,
        'a11y_checked' => 'mixed',
    ]);
});

it('refuses an unknown role', function () {
    Pressable::make()->a11yRole('slider');
})->throws(InvalidArgumentException::class, 'Unknown a11y-role [slider]');

it('refuses an unknown live mode', function () {
    Text::make('Saved')->a11yLive('rude');
})->throws(InvalidArgumentException::class, 'Unknown a11y-live [rude]');

it('refuses a checked value that is not a boolean or mixed', function () {
    Pressable::make()->applyA11yAttributes(['a11y-checked' => 'sometimes']);
})->throws(InvalidArgumentException::class, 'a11y-checked takes true or false or mixed');

// ── Callbacks reach the component ───────────────────

it('runs the adjust callbacks', function () {
    Native::test(ComposedControlsScreen::class)
        ->a11yIncrement('volume')
        ->assertSet('volume', 50)
        ->a11yDecrement('volume')
        ->a11yDecrement('volume')
        ->assertSet('volume', 30);
});

it('runs a custom action from the actions menu', function () {
    Native::test(ComposedControlsScreen::class)
        ->a11yAction('row', 'Delete')
        ->assertSet('removed', 42);
});

it('reports an action the node does not offer', function () {
    Native::test(ComposedControlsScreen::class)->a11yAction('row', 'Archive');
})->throws(AssertionFailedError::class, 'No accessibility action [Archive] on [row]. Actions: Delete');

it('runs the escape action on a modal', function () {
    Native::test(ComposedControlsScreen::class)->a11yDismiss('dialog')->assertRerendered();
});

it('refuses to tap a disabled pressable', function () {
    Native::test(ComposedControlsScreen::class)->tap('urgent');
})->throws(AssertionFailedError::class, 'is disabled');

it('checks disabled on the pressable tapped, not another with the same handler', function () {
    $screen = Native::test(ComposedControlsScreen::class);

    $screen->tap('copy')->assertRerendered();
    $screen->tap('Save copy')->assertRerendered();

    expect(fn () => $screen->tap('draft'))->toThrow(AssertionFailedError::class, 'is disabled')
        ->and(fn () => $screen->tap('Save draft'))->toThrow(AssertionFailedError::class, 'is disabled');
});

it('still taps an enabled pressable', function () {
    Native::test(ComposedControlsScreen::class)
        ->set('priority', 'urgent')
        ->tap('routine')
        ->assertSet('priority', 'routine');
});

// ── Accessibility facade ────────────────────────────

it('sends focus moves and announcements over the bridge', function () {
    Native::test(ComposedControlsScreen::class)
        ->call('save')
        ->assertNativeCalled('Accessibility.Focus', fn (array $p) => $p === ['ref' => 'priority'])
        ->assertNativeCalled('Accessibility.Announce', fn (array $p) => $p === ['message' => 'Draft saved', 'assertive' => false])
        ->assertNativeCallOrder(['Accessibility.Focus', 'Accessibility.Announce']);
});

it('asserts announcements and focus moves by message and ref', function () {
    $screen = Native::test(ComposedControlsScreen::class)->call('save');

    $screen->assertAnnounced()
        ->assertAnnounced('Draft saved')
        ->assertAnnounced('Draft saved', assertive: false)
        ->assertFocused()
        ->assertFocused('priority');

    expect(fn () => $screen->assertAnnounced('Saved'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $screen->assertAnnounced('Draft saved', assertive: true))->toThrow(AssertionFailedError::class)
        ->and(fn () => $screen->assertFocused('terms'))->toThrow(AssertionFailedError::class);
});

it('fails the announcement and focus assertions when nothing was sent', function () {
    $screen = Native::test(ComposedControlsScreen::class);

    expect(fn () => $screen->assertAnnounced())->toThrow(AssertionFailedError::class)
        ->and(fn () => $screen->assertFocused())->toThrow(AssertionFailedError::class);
});

// ── assertAccessible ────────────────────────────────

it('passes the composed controls screen', function () {
    Native::test(ComposedControlsScreen::class)->assertAccessible();
});

it('reports composed controls that are missing their state', function () {
    expect(Native::test(IncompleteComposedScreen::class)->accessibilityViolations())->toEqualCanonicalizing([
        'checkbox <pressable> [ref=terms] has no a11y-checked',
        'adjustable <gesture_area> [ref=volume] has no a11y-value',
        'adjustable <gesture_area> [ref=volume] needs both @a11yIncrement and @a11yDecrement',
        'progress <gesture_area> [ref=upload] has no a11y-value',
        'tablist <column> [ref=tabs] has no a11y-label',
        'a11y-modal <column> [ref=dialog] has no a11y-label to use as its pane title',
        '<button> [ref=save] ignores a11y-role: native controls announce their own role and state',
    ]);
});
