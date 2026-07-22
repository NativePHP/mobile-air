<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

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
        foreach ([
            'font-size' => 'fontSize',
            'font-weight' => 'fontWeight',
            'font-style' => 'fontStyle',
            'font-family' => 'fontFamily',
            'line-through' => 'lineThrough',
            'text-transform' => 'textTransform',
            'letter-spacing' => 'letterSpacing',
            'line-height' => 'lineHeight',
            'line-height-px' => 'lineHeightPx',
            'text-align' => 'textAlign',
            'max-lines' => 'maxLines',
            'content-transition' => 'contentTransition',
        ] as $kebab => $camel) {
            if (isset($attrs[$kebab]) && ! isset($attrs[$camel])) {
                $attrs[$camel] = $attrs[$kebab];
            }
        }

        if (isset($attrs['text'])) {
            $this->textProps['text'] = $attrs['text'];
        }
        if (isset($attrs['fontSize'])) {
            $this->fontSize((float) $attrs['fontSize']);
        }
        if (isset($attrs['fontWeight'])) {
            $this->fontWeight((int) $attrs['fontWeight']);
        }
        if (isset($attrs['fontStyle'])) {
            $this->fontStyle((int) $attrs['fontStyle']);
        }
        if (isset($attrs['fontFamily'])) {
            $this->textProps['font_family'] = (int) $attrs['fontFamily'];
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
        if (isset($attrs['lineThrough'])) {
            $this->textProps['line_through'] = (int) $attrs['lineThrough'];
        }
        if (isset($attrs['textTransform'])) {
            $this->textProps['text_transform'] = (int) $attrs['textTransform'];
        }
        if (isset($attrs['letterSpacing'])) {
            $this->textProps['letter_spacing'] = (float) $attrs['letterSpacing'];
        }
        // Line height (leading). `line_height` is a unitless multiplier of the
        // font size; `line_height_px` is an absolute override. Renderers apply
        // px when present, else multiplier × font size.
        if (isset($attrs['lineHeight'])) {
            $this->textProps['line_height'] = (float) $attrs['lineHeight'];
        }
        if (isset($attrs['lineHeightPx'])) {
            $this->textProps['line_height_px'] = (float) $attrs['lineHeightPx'];
        }
        if (isset($attrs['color'])) {
            $this->color($attrs['color']);
        }
        if (isset($attrs['textAlign'])) {
            $this->textAlign((int) $attrs['textAlign']);
        }
        if (isset($attrs['maxLines'])) {
            $this->maxLines((int) $attrs['maxLines']);
        }
        if (isset($attrs['contentTransition'])) {
            $this->contentTransition((string) $attrs['contentTransition']);
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

    public function textAlign(int $align): static
    {
        $this->textProps['text_align'] = $align;

        return $this;
    }

    public function maxLines(int $lines): static
    {
        $this->textProps['max_lines'] = $lines;

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
