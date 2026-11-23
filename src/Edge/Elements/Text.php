<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Enums\TextAlign;

class Text extends Element
{
    protected string $type = 'text';

    protected array $textProps = [];

    public static function make(string $text = ''): static
    {
        $el = new static;
        if ($text !== '') {
            $el->textProps['text'] = $text;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        // Attributes arrive verbatim from the precompiler; Tailwind classes
        // parse to the camelCase keys below, but hand-written attributes use
        // the documented kebab spellings. Accept both, camelCase winning.
        if (isset($attrs['text'])) {
            $this->textProps['text'] = $attrs['text'];
        }
        if (($fontSize = $attrs['fontSize'] ?? $attrs['font-size'] ?? null) !== null) {
            $this->fontSize((float) $fontSize);
        }
        if (($fontWeight = $attrs['fontWeight'] ?? $attrs['font-weight'] ?? null) !== null) {
            $this->fontWeight((int) $fontWeight);
        }
        if (($fontStyle = $attrs['fontStyle'] ?? $attrs['font-style'] ?? null) !== null) {
            $this->fontStyle((int) $fontStyle);
        }
        if (($fontFamily = $attrs['fontFamily'] ?? $attrs['font-family'] ?? null) !== null) {
            $this->textProps['font_family'] = (int) $fontFamily;
        }
        // Custom font by name (e.g. `font="Inter-Bold"`). The token is a font
        // file (minus extension) bundled from the app's resources/fonts/ by the
        // native-ui copy_assets hook; the native text renderers resolve it.
        if (isset($attrs['font'])) {
            $this->textProps['font_name'] = (string) $attrs['font'];
        }
        if (isset($attrs['underline'])) {
            $this->textProps['underline'] = (int) $attrs['underline'];
        }
        if (($lineThrough = $attrs['lineThrough'] ?? $attrs['line-through'] ?? null) !== null) {
            $this->textProps['line_through'] = (int) $lineThrough;
        }
        if (($textTransform = $attrs['textTransform'] ?? $attrs['text-transform'] ?? null) !== null) {
            $this->textProps['text_transform'] = (int) $textTransform;
        }
        if (($letterSpacing = $attrs['letterSpacing'] ?? $attrs['letter-spacing'] ?? null) !== null) {
            $this->textProps['letter_spacing'] = (float) $letterSpacing;
        }
        // Outlined text: the glyphs drawn hollow, as an outline this many
        // points wide in the text colour. Inherited by nested runs; 0 turns
        // it off again.
        if (($textOutline = $attrs['textOutline'] ?? $attrs['text-outline'] ?? null) !== null) {
            $this->textProps['text_outline'] = (float) $textOutline;
        }
        // Line height (leading). `line_height` is a unitless multiplier of the
        // font size; `line_height_px` is an absolute override. Renderers apply
        // px when present, else multiplier × font size.
        if (($lineHeight = $attrs['lineHeight'] ?? $attrs['line-height'] ?? null) !== null) {
            $this->textProps['line_height'] = (float) $lineHeight;
        }
        if (($lineHeightPx = $attrs['lineHeightPx'] ?? $attrs['line-height-px'] ?? null) !== null) {
            $this->textProps['line_height_px'] = (float) $lineHeightPx;
        }
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }
        if (($textAlign = $attrs['textAlign'] ?? $attrs['text-align'] ?? null) !== null) {
            $this->textAlign($textAlign);
        }
        if (($maxLines = $attrs['maxLines'] ?? $attrs['max-lines'] ?? null) !== null) {
            $this->maxLines((int) $maxLines);
        }
        if (($minScale = $attrs['minScale'] ?? $attrs['min-scale'] ?? null) !== null) {
            $this->minScale((float) $minScale);
        }
        if (($contentTransition = $attrs['contentTransition'] ?? $attrs['content-transition'] ?? null) !== null) {
            $this->contentTransition((string) $contentTransition);
        }
    }

    public function fontSize(float $size): static
    {
        $this->textProps['font_size'] = $size;

        return $this;
    }

    /**
     * Render in a custom font. The name is a font file bundled from the app's
     * resources/fonts/ (e.g. `Inter-Bold` for `Inter-Bold.ttf`). Unresolvable
     * names fall back to the system font in the renderer.
     */
    public function font(string $name): static
    {
        $this->textProps['font_name'] = $name;

        return $this;
    }

    /**
     * Font weight on a 1–7 ordinal scale: 1 thin, 2 light, 3 regular,
     * 4 medium, 5 semibold, 6 bold, 7 heaviest (iOS `.heavy` / Android
     * `ExtraBold`). Clamped to that range — both renderers fall back to
     * regular for out-of-range ints, so an off-scale value (e.g. a CSS-style
     * `900`, or `8`) would otherwise silently render unweighted. Clamping
     * rounds to the nearest supported weight instead.
     */
    public function fontWeight(int $weight): static
    {
        $this->textProps['font_weight'] = max(1, min(7, $weight));

        return $this;
    }

    /** Font style: 1 = italic, 0 = normal. */
    public function fontStyle(int $style): static
    {
        $this->textProps['font_style'] = $style;

        return $this;
    }

    public function italic(): static
    {
        $this->textProps['font_style'] = 1;

        return $this;
    }

    public function bold(): static
    {
        $this->textProps['font_weight'] = 7;

        return $this;
    }

    public function color(string $color): static
    {
        $this->textProps['color'] = $color;

        return $this;
    }

    public function textAlign(int|string|TextAlign $align): static
    {
        if (($resolved = TextAlign::parse($align)) !== null) {
            $this->textProps['text_align'] = $resolved;
        }

        return $this;
    }

    public function maxLines(int $lines): static
    {
        $this->textProps['max_lines'] = $lines;

        return $this;
    }

    /**
     * Let text that is too wide for its lines shrink, down to this share of
     * its font size, before it truncates. Pair it with `maxLines(1)` to keep
     * a value like a big number on one line at large system text sizes: iOS
     * maps it to `minimumScaleFactor`, Android to auto-size (plain text only;
     * text with inline runs keeps its size there). Clamped to 0–1; 1, the
     * default, never shrinks.
     */
    public function minScale(float $scale): static
    {
        $this->textProps['min_scale'] = max(0.0, min(1.0, $scale));

        return $this;
    }

    /**
     * Animate in-place changes to this element's text instead of swapping it
     * cold. `numeric` is the counter/timer treatment: iOS maps it to SwiftUI's
     * `.contentTransition(.numericText(value:))` (digits roll up as the value
     * grows, down as it shrinks); Android approximates the roll with a
     * directional slide + fade, since Compose has no numericText equivalent.
     * `opacity` crossfades on both platforms, and `interpolate` (iOS-only
     * glyph interpolation) falls back to a crossfade on Android.
     *
     * The roll direction comes from parsing the text as a number, so it only
     * counts up/down for plain numeric strings ("42", "3.14"); anything else
     * still animates, just without direction. Requires a stable node identity
     * across renders — pair with `->key(...)` inside loops.
     */
    public function contentTransition(string $transition): static
    {
        $this->textProps['content_transition'] = $transition;

        return $this;
    }

    /** Sugar for `contentTransition('numeric')` — rolling counter digits. */
    public function numericTransition(): static
    {
        return $this->contentTransition('numeric');
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->textProps;
    }
}
