<?php

namespace Native\Mobile\Edge\Concerns;

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
 * Values are written via `Element::setProp()`, which stores them in the
 * base class's `extraProps` — merged into the serialized node's props by
 * `getResolvedProps()` regardless of which private props array the
 * concrete element uses for its own keys. `resolveProps()` output wins on
 * key conflict, so elements must not set `a11y_*` keys there.
 */
trait HasA11y
{
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

    /**
     * Hydrate the a11y props from Blade attributes. The Blade precompiler
     * keeps attribute names verbatim, so both the kebab-case spelling
     * (`a11y-label` / `a11y-hint`) and the camelCase one (`a11yLabel` /
     * `a11yHint`) must be accepted. Call this from `applyAttributes()`.
     */
    protected function applyA11yAttributes(array $attrs): void
    {
        if (isset($attrs['a11y-label']) || isset($attrs['a11yLabel'])) {
            $this->a11yLabel($attrs['a11y-label'] ?? $attrs['a11yLabel']);
        }
        if (isset($attrs['a11y-hint']) || isset($attrs['a11yHint'])) {
            $this->a11yHint($attrs['a11y-hint'] ?? $attrs['a11yHint']);
        }
    }
}
