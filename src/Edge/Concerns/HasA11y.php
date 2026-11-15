<?php

namespace Native\Mobile\Edge\Concerns;

use InvalidArgumentException;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * Screen-reader accessibility props for Edge elements. Available to any
 * plugin element — `use` it in an Element subclass and call
 * `$this->applyA11yAttributes($attrs)` from `applyAttributes()`.
 *
 * Wire contract (key names are consumed by the native renderers — do not
 * rename):
 *
 *   - `a11y_label` — what the screen reader announces for the element.
 *     iOS maps it to `accessibilityLabel`; Android to `contentDescription`.
 *     Required on icon-only controls, where there is no visible text for
 *     the reader to fall back on.
 *   - `a11y_hint` — supplementary usage guidance, read after the label.
 *     iOS maps it to `accessibilityHint`; Android appends it to the
 *     `contentDescription`.
 *
 * The rest describe a control composed from core elements (`pressable`,
 * `column`, `row`, `stack`, `gesture_area`, `text`, `icon`, `image`), so it
 * can tell the screen reader what a native control would:
 *
 *   - `a11y_role` — one of ROLES. Absent: a tappable pressable is a button.
 *   - `a11y_checked` — `'true'`, `'false'` or `'mixed'`; `''` when given null.
 *   - `a11y_selected`, `a11y_expanded` — booleans, present only when set.
 *     Pass false rather than null for a node that can expand, and keep
 *     `a11y-value-now` set on a node that has one: on iOS a prop that comes
 *     and goes between renders rebuilds the view under it.
 *   - `a11y_state` — a state in words, when no built-in one fits.
 *   - `a11y_error` — the node's error.
 *   - `a11y_value` — the spoken value; `a11y_value_now`, `a11y_value_min`
 *     and `a11y_value_max` give Android its numeric range.
 *   - `on_a11y_increment`, `on_a11y_decrement` — callback ids for an
 *     `adjustable` node; `on_a11y_dismiss` — the escape action of a modal.
 *   - `a11y_action_labels`, `a11y_action_callbacks` — parallel lists for
 *     the screen reader's actions menu (callback ids as strings).
 *   - `a11y_hidden`, `a11y_modal` — booleans.
 *   - `a11y_pane_title` — announced when the container appears.
 *   - `a11y_live` — `'polite'` or `'assertive'`.
 *
 * Values are written via `Element::setProp()`, which stores them in the
 * base class's `extraProps` — merged into the serialized node's props by
 * `getResolvedProps()` regardless of which private props array the
 * concrete element uses for its own keys. `resolveProps()` output wins on
 * key conflict, so elements must not set `a11y_*` keys there.
 */
trait HasA11y
{
    /** Roles a composed control can announce itself as. */
    public const A11Y_ROLES = [
        'button', 'checkbox', 'switch', 'radio', 'radiogroup', 'tab', 'tablist',
        'dropdown', 'adjustable', 'progress', 'header', 'image', 'none',
    ];

    private ?string $a11yIncrementMethod = null;

    private ?string $a11yDecrementMethod = null;

    private ?string $a11yDismissMethod = null;

    /** @var list<Element>|null Null until a11yActions() is called. */
    private ?array $a11yActionElements = null;

    /**
     * What the screen reader announces for this element.
     * iOS: `accessibilityLabel`. Android: `contentDescription`.
     */
    public function a11yLabel(string $value): static
    {
        $this->setProp('a11y_label', $value);

        return $this;
    }

    /**
     * Supplementary usage guidance, read after the label.
     * iOS: `accessibilityHint`. Android: appended to `contentDescription`.
     */
    public function a11yHint(string $value): static
    {
        $this->setProp('a11y_hint', $value);

        return $this;
    }

    /** What kind of control this node is; one of A11Y_ROLES. */
    public function a11yRole(string $role): static
    {
        if (! in_array($role, self::A11Y_ROLES, true)) {
            throw new InvalidArgumentException("Unknown a11y-role [{$role}]. Use one of: ".implode(', ', self::A11Y_ROLES).'.');
        }

        $this->setProp('a11y_role', $role);

        return $this;
    }

    /** A checkbox's or switch's state: true, false, or 'mixed'. */
    public function a11yChecked(bool|string $checked): static
    {
        if (is_string($checked) && $checked !== 'mixed') {
            $checked = self::a11yBool($checked, 'a11y-checked');
        }

        $this->setProp('a11y_checked', match ($checked) {
            true => 'true',
            false => 'false',
            default => 'mixed',
        });

        return $this;
    }

    /** Whether a radio, tab, or other selectable node is selected. */
    public function a11ySelected(bool $selected = true): static
    {
        $this->setProp('a11y_selected', $selected);

        return $this;
    }

    /** Whether a disclosure row or dropdown is expanded. */
    public function a11yExpanded(bool $expanded = true): static
    {
        $this->setProp('a11y_expanded', $expanded);

        return $this;
    }

    /** A state in words when no built-in one fits, such as "Downloading". */
    public function a11yState(string $state): static
    {
        $this->setProp('a11y_state', $state);

        return $this;
    }

    /** The node's error, such as a validation message. Empty clears it. */
    public function a11yError(string $error): static
    {
        $this->setProp('a11y_error', $error);

        return $this;
    }

    /** The spoken value, such as "40%" or "3 of 5". */
    public function a11yValue(string $value): static
    {
        $this->setProp('a11y_value', $value);

        return $this;
    }

    /** The numeric value and its range, for adjustable and progress nodes. */
    public function a11yRange(float $now, float $min = 0, float $max = 100): static
    {
        $this->setProp('a11y_value_now', $now);
        $this->setProp('a11y_value_min', $min);
        $this->setProp('a11y_value_max', $max);

        return $this;
    }

    /** Called when a screen-reader user swipes up on an adjustable node. */
    public function onA11yIncrement(string $method): static
    {
        $this->a11yIncrementMethod = $method;

        return $this;
    }

    /** Called when a screen-reader user swipes down on an adjustable node. */
    public function onA11yDecrement(string $method): static
    {
        $this->a11yDecrementMethod = $method;

        return $this;
    }

    /** Called by the screen reader's escape action on a modal container. */
    public function onA11yDismiss(string $method): static
    {
        $this->a11yDismissMethod = $method;

        return $this;
    }

    /**
     * Actions offered in the screen reader's actions menu, so a gesture
     * such as swipe-to-delete has an alternative. Takes the same items as
     * a pressable's `:context-menu`; dividers are skipped.
     *
     * @param  array<NavAction|Element>  $actions
     */
    public function a11yActions(array $actions): static
    {
        $this->a11yActionElements = [];

        foreach ($actions as $action) {
            if ($action instanceof NavAction) {
                $action = $action->toElement();
            }
            if (! $action instanceof Element) {
                throw new InvalidArgumentException('a11y-actions takes NavAction items.');
            }
            $this->a11yActionElements[] = $action;
        }

        return $this;
    }

    /** Hide this subtree from screen readers, for decoration. */
    public function a11yHidden(bool $hidden = true): static
    {
        $this->setProp('a11y_hidden', $hidden);

        return $this;
    }

    /** Keep the screen reader inside this container while it is shown. */
    public function a11yModal(bool $modal = true): static
    {
        $this->setProp('a11y_modal', $modal);

        return $this;
    }

    /** A title announced when this container appears or changes as a whole. */
    public function a11yPaneTitle(string $title): static
    {
        $this->setProp('a11y_pane_title', $title);

        return $this;
    }

    /** Announce this node's text when a render changes it: 'polite' or 'assertive'. */
    public function a11yLive(string $mode = 'polite'): static
    {
        if (! in_array($mode, ['polite', 'assertive'], true)) {
            throw new InvalidArgumentException("Unknown a11y-live [{$mode}]. Use polite or assertive.");
        }

        $this->setProp('a11y_live', $mode);

        return $this;
    }

    /**
     * Hydrate the a11y props from Blade attributes. The Blade precompiler
     * keeps attribute names verbatim, so both the kebab-case spelling
     * (`a11y-label` / `a11y-hint`) and the camelCase one (`a11yLabel` /
     * `a11yHint`) must be accepted. Call this from `applyAttributes()`.
     * `NativeElementCollector` also calls it for every element, so it is
     * safe to call twice.
     */
    public function applyA11yAttributes(array $attrs): void
    {
        $camel = fn (string $kebab): string => lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $kebab))));
        $attr = fn (string $kebab): mixed => $attrs[$kebab] ?? $attrs[$camel($kebab)] ?? null;
        $given = fn (string $kebab): bool => array_key_exists($kebab, $attrs) || array_key_exists($camel($kebab), $attrs);

        foreach (['a11y-label' => 'a11yLabel', 'a11y-hint' => 'a11yHint', 'a11y-role' => 'a11yRole'] as $name => $method) {
            if (($value = $attr($name)) !== null) {
                $this->{$method}((string) $value);
            }
        }

        // State that comes and goes, such as `:a11y-error="$errors->first('email')"`,
        // is sent as '' while null, so the prop is always on the node and the
        // renderer doesn't rebuild the view the moment a message appears.
        foreach ([
            'a11y-state' => 'a11yState',
            'a11y-error' => 'a11yError',
            'a11y-value' => 'a11yValue',
            'a11y-pane-title' => 'a11yPaneTitle',
        ] as $name => $method) {
            if ($given($name)) {
                $this->{$method}((string) $attr($name));
            }
        }

        if (($live = $attr('a11y-live')) !== null) {
            // A bare `a11y-live` arrives as true; it means the fluent default.
            $this->a11yLive($live === true ? 'polite' : (string) $live);
        }

        if (($value = $attr('a11y-checked')) !== null) {
            $this->a11yChecked($value === 'mixed' ? 'mixed' : self::a11yBool($value, 'a11y-checked'));
        } elseif ($given('a11y-checked')) {
            // Null: no state yet, but keep the prop on the node (see above).
            $this->setProp('a11y_checked', '');
        }

        foreach ([
            'a11y-selected' => 'a11ySelected',
            'a11y-expanded' => 'a11yExpanded',
            'a11y-hidden' => 'a11yHidden',
            'a11y-modal' => 'a11yModal',
        ] as $name => $method) {
            if (($value = $attr($name)) !== null) {
                $this->{$method}(self::a11yBool($value, $name));
            }
        }

        $now = $attr('a11y-value-now');
        if ($now !== null) {
            $this->a11yRange((float) $now, (float) ($attr('a11y-value-min') ?? 0), (float) ($attr('a11y-value-max') ?? 100));
        }

        if ($given('a11y-actions')) {
            $this->a11yActions(is_array($actions = $attr('a11y-actions')) ? $actions : []);
        }

        if (isset($attrs['_a11yIncrement'])) {
            $this->onA11yIncrement($attrs['_a11yIncrement']);
        }
        if (isset($attrs['_a11yDecrement'])) {
            $this->onA11yDecrement($attrs['_a11yDecrement']);
        }
        if (isset($attrs['_a11yDismiss'])) {
            $this->onA11yDismiss($attrs['_a11yDismiss']);
        }
    }

    /**
     * Register the a11y callbacks and actions into the resolved props.
     * Called from Element::getResolvedProps().
     */
    protected function resolveA11yCallbacks(CallbackRegistry $registry, array $props): array
    {
        foreach ([
            'on_a11y_increment' => $this->a11yIncrementMethod,
            'on_a11y_decrement' => $this->a11yDecrementMethod,
            'on_a11y_dismiss' => $this->a11yDismissMethod,
        ] as $key => $method) {
            if ($method !== null) {
                $props[$key] = $registry->register($method);
            }
        }

        if ($this->a11yActionElements !== null) {
            $labels = [];
            $callbacks = [];

            foreach ($this->a11yActionElements as $action) {
                $node = $action->toArray($registry);
                $label = $node['props']['a11y_label'] ?? $node['props']['label'] ?? '';
                $press = $node['on_press'] ?? null;

                if (! empty($node['props']['divider']) || ! is_int($press) || $label === '') {
                    continue;
                }

                $labels[] = (string) $label;
                $callbacks[] = (string) $press;
            }

            $props['a11y_action_labels'] = $labels;
            $props['a11y_action_callbacks'] = $callbacks;
        }

        return $props;
    }

    private static function a11yBool(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException("{$name} takes true or false".($name === 'a11y-checked' ? ' or mixed' : '').', got ['.var_export($value, true).'].');
        }

        return $parsed;
    }
}
