<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Row;
use Native\Mobile\Edge\Elements\ScrollView;
use Native\Mobile\Edge\Elements\Stack;

class NativeElementCollector
{
    protected static array $stack = [];

    protected static array $roots = [];

    protected static bool $streaming = false;

    protected static ?CallbackRegistry $callbacks = null;

    /**
     * Phase 1 — Blade-side key-path stack for the streaming render path.
     *
     * `<native:row native:key="row-{{ $i }}">` works the same way
     * `Element::row(...)->key('row-$i')` does in the programmatic path:
     * an explicit `native:key` attr derives a stable node id via
     * FNV-1a hash of `(parent_path . '/' . key)`, so the native diff
     * sees the same id for the same logical row across renders even
     * when siblings reorder/insert.
     *
     * The stack holds one entry per currently-open container; the top
     * is the parent path used when hashing the next opened/leaf node.
     * `openStreaming` pushes, `closeStreaming` pops, `leafStreaming`
     * computes-but-doesn't-push. Empty string at the root means
     * "unkeyed parent" — children without `native:key` then fall
     * through to the streaming writer's auto-generated positional id.
     */
    protected static array $keyPathStack = [];

    /**
     * Frame-level re-render intervals (ms) collected from `native:poll`
     * attributes during the current render. Drained by the component via
     * takePollIntervals() before the tree is collected/published.
     */
    protected static array $pollIntervals = [];

    // ── Streaming control ────────────────────────────

    public static function setStreaming(bool $enabled): void
    {
        static::$streaming = $enabled;
    }

    public static function isStreaming(): bool
    {
        return static::$streaming;
    }

    public static function setCallbacks(CallbackRegistry $callbacks): void
    {
        static::$callbacks = $callbacks;
    }

    /**
     * Phase 1 — pull the `native:key` attribute off an attrs array and
     * derive the {nodeId, myKeyPath} pair to publish.
     *
     * Returns [id, myKeyPath]:
     *   - id = 0           → no key set; let the C streaming writer
     *                        auto-generate (legacy positional id).
     *   - id = u32 hash    → caller passes this as the `$id` argument
     *                        to nphp_node_open/_leaf to override the
     *                        auto-id.
     *   - myKeyPath = string for the depth-stack (the parent path of
     *                 this node's children). Inherits the parent path
     *                 when no `native:key` is set, so a keyed
     *                 great-grandparent still informs descendants
     *                 that *do* key themselves.
     *
     * Mutates `$attrs` to strip the `native:key` entry so it doesn't
     * leak into props/layout/style downstream.
     */
    protected static function resolveStreamingKey(array &$attrs): array
    {
        $parentPath = empty(static::$keyPathStack)
            ? ''
            : end(static::$keyPathStack);

        // `native-key` is the precompiled form of `native:key` (the attr parser
        // rejects ':' in names, so NativeTagPrecompiler renames it). Accept the
        // raw colon form too for the programmatic/streaming paths.
        $key = $attrs['native-key'] ?? $attrs['native:key'] ?? null;
        if ($key === null) {
            return [0, $parentPath];
        }

        unset($attrs['native-key'], $attrs['native:key']);
        $myKeyPath = $parentPath.'/'.((string) $key);

        return [Element::fnv1a32($myKeyPath), $myKeyPath];
    }

    // ── Streaming methods (write directly to C) ──────

    public static function openStreaming(string $type, array $attrs): void
    {
        if (isset($attrs['class'])) {
            $classAttrs = TailwindParser::parse($attrs['class']);
            $attrs = array_merge($classAttrs, $attrs);
            unset($attrs['class']);
        }

        [$nodeId, $myKeyPath] = static::resolveStreamingKey($attrs);
        static::$keyPathStack[] = $myKeyPath;

        $builtinTypes = ['column', 'row', 'stack', 'scroll_view', 'pressable', 'canvas'];

        if (in_array($type, $builtinTypes, true)) {
            $layout = static::buildLayoutArray($attrs);
            $style = static::buildStyleArray($attrs);
            $props = static::buildDarkProps($attrs) + static::buildAnimationProps($attrs);
            $onPress = static::resolveOnPress($attrs);
            $onLongPress = static::resolveOnLongPress($attrs);

            // Double-tap rides the props dict (not a dedicated node field).
            if (($doubleTap = static::resolveOnDoubleTap($attrs)) !== 0) {
                $props['on_double_tap'] = $doubleTap;
            }

            // ScrollView needs overflow: scroll so Yoga doesn't constrain children
            if ($type === 'scroll_view' && ! isset($layout['overflow'])) {
                $layout['overflow'] = 2;
            }

            nphp_node_open(
                $type,
                ! empty($layout) ? $layout : null,
                ! empty($style) ? $style : null,
                ! empty($props) ? $props : null,
                $onPress,
                $onLongPress,
                $nodeId,
            );
        } else {
            // Plugin element — instantiate for resolveProps/applyAttributes
            $element = ElementRegistry::resolve($type);
            if (! $element) {
                throw new \RuntimeException("Unknown native element type: {$type}");
            }

            $element->applyAttributes($attrs);
            static::applyLayout($element, $attrs);
            static::applyStyle($element, $attrs);
            static::applyCallbacks($element, $attrs);
            static::applyElementProps($element, $attrs);

            $layout = $element->getLayout();
            $style = $element->getStyle();
            $props = $element->getResolvedProps(static::$callbacks);
            $darkProps = static::buildDarkProps($attrs);
            if (! empty($darkProps)) {
                $props = array_merge($props ?? [], $darkProps);
            }
            $onPress = $element->getPressCallbackId(static::$callbacks);
            $onLongPress = $element->getLongPressCallbackId(static::$callbacks);

            nphp_node_open(
                $type,
                ! empty($layout) ? $layout : null,
                ! empty($style) ? $style : null,
                ! empty($props) ? $props : null,
                $onPress,
                $onLongPress,
                $nodeId,
            );
        }
    }

    public static function closeStreaming(): void
    {
        nphp_node_close();
        // Phase 1 — pop the matching key-path entry pushed by openStreaming.
        array_pop(static::$keyPathStack);
    }

    public static function leafStreaming(string $type, array $attrs): void
    {
        if (isset($attrs['class'])) {
            $classAttrs = TailwindParser::parse($attrs['class']);
            $attrs = array_merge($classAttrs, $attrs);
            unset($attrs['class']);
        }

        // Phase 1 — leaves derive an id from `native:key` but don't
        // push onto the path stack (no children to inherit it).
        [$nodeId/* $myKeyPath unused for leaves */] = static::resolveStreamingKey($attrs);

        $builtinTypes = ['column', 'row', 'stack', 'scroll_view', 'pressable', 'canvas'];

        if (in_array($type, $builtinTypes, true)) {
            $layout = static::buildLayoutArray($attrs);
            $style = static::buildStyleArray($attrs);
            $props = static::buildDarkProps($attrs) + static::buildAnimationProps($attrs);
            $onPress = static::resolveOnPress($attrs);
            $onLongPress = static::resolveOnLongPress($attrs);

            // Double-tap rides the props dict (not a dedicated node field).
            if (($doubleTap = static::resolveOnDoubleTap($attrs)) !== 0) {
                $props['on_double_tap'] = $doubleTap;
            }

            nphp_node_leaf(
                $type,
                ! empty($layout) ? $layout : null,
                ! empty($style) ? $style : null,
                ! empty($props) ? $props : null,
                $onPress,
                $onLongPress,
                $nodeId,
            );
        } else {
            // Plugin element — instantiate for resolveProps/applyAttributes
            $element = ElementRegistry::resolve($type);
            if (! $element) {
                throw new \RuntimeException("Unknown native element type: {$type}");
            }

            $element->applyAttributes($attrs);
            static::applyLayout($element, $attrs);
            static::applyStyle($element, $attrs);
            static::applyCallbacks($element, $attrs);
            static::applyElementProps($element, $attrs);

            $layout = $element->getLayout();
            $style = $element->getStyle();
            $props = $element->getResolvedProps(static::$callbacks);
            $darkProps = static::buildDarkProps($attrs);
            if (! empty($darkProps)) {
                $props = array_merge($props ?? [], $darkProps);
            }
            $onPress = $element->getPressCallbackId(static::$callbacks);
            $onLongPress = $element->getLongPressCallbackId(static::$callbacks);

            nphp_node_leaf(
                $type,
                ! empty($layout) ? $layout : null,
                ! empty($style) ? $style : null,
                ! empty($props) ? $props : null,
                $onPress,
                $onLongPress,
                $nodeId,
            );
        }
    }

    // ── Layout/style array builders ──────────────────

    public static function buildLayoutArray(array $attrs): array
    {
        $layout = [];

        if (! empty($attrs['fill'])) {
            $layout['width'] = 'fill';
            $layout['height'] = 'fill';
        }
        if (! empty($attrs['fillWidth'])) {
            $layout['width'] = 'fill';
        }
        if (! empty($attrs['fillHeight'])) {
            $layout['height'] = 'fill';
        }
        if (isset($attrs['width'])) {
            $layout['width'] = $attrs['width'];
        }
        if (isset($attrs['height'])) {
            $layout['height'] = $attrs['height'];
        }

        // Padding
        $uniformPadding = isset($attrs['padding']) && ! is_array($attrs['padding']) ? (float) $attrs['padding'] : null;
        $pt = $attrs['paddingTop'] ?? null;
        $pr = $attrs['paddingRight'] ?? null;
        $pb = $attrs['paddingBottom'] ?? null;
        $pl = $attrs['paddingLeft'] ?? null;

        if ($pt !== null || $pr !== null || $pb !== null || $pl !== null) {
            $base = $uniformPadding ?? 0;
            $layout['padding'] = [
                (float) ($pt ?? $base),
                (float) ($pr ?? $base),
                (float) ($pb ?? $base),
                (float) ($pl ?? $base),
            ];
        } elseif (isset($attrs['padding'])) {
            $layout['padding'] = is_array($attrs['padding'])
                ? array_map('floatval', $attrs['padding'])
                : (float) $attrs['padding'];
        }

        // Margin
        $uniformMargin = isset($attrs['margin']) && ! is_array($attrs['margin']) ? (float) $attrs['margin'] : null;
        $mt = $attrs['marginTop'] ?? null;
        $mr = $attrs['marginRight'] ?? null;
        $mb = $attrs['marginBottom'] ?? null;
        $ml = $attrs['marginLeft'] ?? null;

        if ($mt !== null || $mr !== null || $mb !== null || $ml !== null) {
            $base = $uniformMargin ?? 0;
            $layout['margin'] = [
                (float) ($mt ?? $base),
                (float) ($mr ?? $base),
                (float) ($mb ?? $base),
                (float) ($ml ?? $base),
            ];
        } elseif (isset($attrs['margin'])) {
            $layout['margin'] = is_array($attrs['margin'])
                ? array_map('floatval', $attrs['margin'])
                : (float) $attrs['margin'];
        }

        if (isset($attrs['gap'])) {
            $layout['gap'] = (float) $attrs['gap'];
        }
        if (! empty($attrs['center'])) {
            $layout['align_items'] = 1;
            $layout['justify_content'] = 1;
        }
        if (! empty($attrs['safeArea'])) {
            $layout['safe_area'] = 1;            // both edges
        }
        if (! empty($attrs['safeAreaTop'])) {
            $layout['safe_area'] = 2;            // top only
        }
        if (! empty($attrs['safeAreaBottom'])) {
            $layout['safe_area'] = 3;            // bottom only
        }
        if (isset($attrs['flexGrow'])) {
            $layout['flex_grow'] = (float) $attrs['flexGrow'];
        }
        if (isset($attrs['flexShrink'])) {
            $layout['flex_shrink'] = (float) $attrs['flexShrink'];
        }
        if (isset($attrs['flexBasis'])) {
            $layout['flex_basis'] = (float) $attrs['flexBasis'];
        }
        if (isset($attrs['flexWrap'])) {
            $layout['flex_wrap'] = (int) $attrs['flexWrap'];
        }
        if (isset($attrs['aspectRatio'])) {
            $layout['aspect_ratio'] = (float) $attrs['aspectRatio'];
        }
        if (isset($attrs['alignSelf'])) {
            $layout['align_self'] = (int) $attrs['alignSelf'];
        }
        if (isset($attrs['alignItems'])) {
            $layout['align_items'] = (int) $attrs['alignItems'];
        }
        if (isset($attrs['justifyContent'])) {
            $layout['justify_content'] = (int) $attrs['justifyContent'];
        }
        if (isset($attrs['positionType'])) {
            $layout['position_type'] = (int) $attrs['positionType'];
        }
        if (isset($attrs['positionTop']) || isset($attrs['positionRight'])
            || isset($attrs['positionBottom']) || isset($attrs['positionLeft'])) {
            // [top, right, bottom, left] — same order as Element::insets()
            $layout['position'] = [
                (float) ($attrs['positionTop'] ?? 0),
                (float) ($attrs['positionRight'] ?? 0),
                (float) ($attrs['positionBottom'] ?? 0),
                (float) ($attrs['positionLeft'] ?? 0),
            ];
        }

        return $layout;
    }

    public static function buildStyleArray(array $attrs): array
    {
        $style = [];

        if (isset($attrs['bg'])) {
            $style['bg_color'] = $attrs['bg'];
        }
        if (isset($attrs['borderRadius'])) {
            $style['border_radius'] = (float) $attrs['borderRadius'];
        }
        if (isset($attrs['borderWidth'], $attrs['borderColor'])) {
            $style['border_width'] = (float) $attrs['borderWidth'];
            $style['border_color'] = $attrs['borderColor'];
        }
        if (isset($attrs['opacity'])) {
            // SharedValue opacity is handled by `buildAnimationProps`
            // — leave style.opacity at 1.0 here so the prop-bag
            // binding wins on the native side.
            if (! ($attrs['opacity'] instanceof SharedValue)) {
                $style['opacity'] = (float) $attrs['opacity'];
            }
        }
        if (isset($attrs['elevation'])) {
            $style['elevation'] = (float) $attrs['elevation'];
        }

        return $style;
    }

    /**
     * Extract animation + transform props from attrs so they ride
     * through to the renderer for builtin elements like
     * `<native:column>`. Without this they'd be silently dropped — the
     * builtin paths only forward layout/style/dark/callbacks.
     *
     * Plugin elements pick these up via their own `applyAttributes` and
     * don't need this helper.
     *
     * Supported props:
     *   - `animate-duration` (ms float, > 0 enables animation)
     *   - `animate-easing`   (string: linear / ease-in / ease-out / ease-in-out)
     *   - `translate-x` / `translate-y` (points, offset from layout position)
     *   - `scale`            (uniform scale factor, 1.0 = identity)
     *   - `rotate`           (degrees)
     */
    public static function buildAnimationProps(array $attrs): array
    {
        $props = [];

        if (isset($attrs['animate-duration'])) {
            $props['animate-duration'] = (float) $attrs['animate-duration'];
        }
        if (isset($attrs['animate-easing'])) {
            $props['animate-easing'] = (string) $attrs['animate-easing'];
        }
        if (isset($attrs['animate-loop'])) {
            $val = $attrs['animate-loop'];
            $props['animate-loop'] = is_string($val)
                ? in_array(strtolower($val), ['true', '1', 'yes'], true)
                : (bool) $val;
        }

        // Transform props — each may be a literal number or a
        // `SharedValue` instance bound to a gesture. When it's a
        // SharedValue, write both the initial value (for static
        // fallback) AND a companion `{key}_sv` string that the native
        // renderer parses to subscribe to live updates.
        foreach (['translate-x', 'translate-y', 'scale', 'rotate'] as $key) {
            if (! isset($attrs[$key])) {
                continue;
            }
            $value = $attrs[$key];
            if ($value instanceof SharedValue) {
                $props[$key] = $value->value();         // initial / current snapshot
                $props[$key.'_sv'] = (string) $value;   // wire-encoded binding
            } else {
                $props[$key] = (float) $value;
            }
        }

        // Opacity is normally a Style prop, but when it's bound to a
        // SharedValue we route the binding through the prop bag here
        // (and `buildStyleArray` zeroes out style.opacity so it's a
        // no-op there). `NodeAnimationModifier` checks `opacity_sv`
        // and reads it from the store.
        if (isset($attrs['opacity']) && $attrs['opacity'] instanceof SharedValue) {
            $props['opacity_sv'] = (string) $attrs['opacity'];
        }

        // Press feedback — native-thread tap response (no PHP roundtrip).
        // Identity values (1.0 scale, 1.0 opacity, 0 translate) mean
        // "not configured"; the renderer treats any non-default as opt-in.
        if (isset($attrs['press-scale'])) {
            $props['press-scale'] = (float) $attrs['press-scale'];
        }
        if (isset($attrs['press-opacity'])) {
            $props['press-opacity'] = (float) $attrs['press-opacity'];
        }
        if (isset($attrs['press-translate-y'])) {
            $props['press-translate-y'] = (float) $attrs['press-translate-y'];
        }

        return $props;
    }

    /**
     * Build dark mode override props from the 'dark' attribute key.
     * Maps TailwindParser output keys to prop names prefixed with 'dark_'.
     */
    public static function buildDarkProps(array $attrs): array
    {
        if (! isset($attrs['dark']) || ! is_array($attrs['dark'])) {
            return [];
        }

        $dark = $attrs['dark'];
        $props = [];

        // Style overrides
        if (isset($dark['bg'])) {
            $props['dark_bg_color'] = $dark['bg'];
        }
        if (isset($dark['borderColor'])) {
            $props['dark_border_color'] = $dark['borderColor'];
        }
        if (isset($dark['opacity'])) {
            $props['dark_opacity'] = (float) $dark['opacity'];
        }

        // Text/color overrides
        if (isset($dark['color'])) {
            $props['dark_color'] = $dark['color'];
        }
        if (isset($dark['fontSize'])) {
            $props['dark_font_size'] = (int) $dark['fontSize'];
        }

        return $props;
    }

    protected static function resolveOnPress(array $attrs): int
    {
        if (isset($attrs['_navigate']) && static::$callbacks) {
            $navKey = static::$callbacks->registerNavigation($attrs['_navigate']);

            return static::$callbacks->register("__navigate('{$navKey}')");
        }

        if (isset($attrs['_press']) && static::$callbacks) {
            return static::$callbacks->register($attrs['_press']);
        }

        return 0;
    }

    protected static function resolveOnLongPress(array $attrs): int
    {
        if (isset($attrs['_longPress']) && static::$callbacks) {
            return static::$callbacks->register($attrs['_longPress']);
        }

        return 0;
    }

    /**
     * Double-tap callback id. Unlike press/long-press (dedicated binary node
     * fields), this travels in the props dict as `on_double_tap` — the same
     * channel as `on_change` / `on_swipe_delete` — so it needs no change to
     * the `nphp_node_*` signatures or the binary wire format. Returns 0 when
     * no `@doubleTap` handler is set.
     */
    protected static function resolveOnDoubleTap(array $attrs): int
    {
        if (isset($attrs['_doubleTap']) && static::$callbacks) {
            return static::$callbacks->register($attrs['_doubleTap']);
        }

        return 0;
    }

    // ── Public methods (delegates to streaming or legacy) ───

    public static function open(string $type, array $attrs): void
    {
        $attrs = static::extractPoll($attrs);

        if (static::$streaming) {
            static::openStreaming($type, $attrs);

            return;
        }

        $element = static::createElement($type, $attrs);
        static::$stack[] = $element;
    }

    /**
     * Pull a compiled `native-poll="<ms>"` attribute off the bag and
     * register it as a frame-level re-render interval. Returns the attrs
     * with the entry stripped so it never leaks into props/layout.
     */
    protected static function extractPoll(array $attrs): array
    {
        if (array_key_exists('native-poll', $attrs)) {
            $ms = (int) $attrs['native-poll'];
            unset($attrs['native-poll']);

            if ($ms > 0) {
                static::$pollIntervals[] = $ms;
            }
        }

        return $attrs;
    }

    /** Read and clear the poll intervals registered during this render. */
    public static function takePollIntervals(): array
    {
        $intervals = array_values(array_unique(static::$pollIntervals));
        static::$pollIntervals = [];

        return $intervals;
    }

    public static function close(): void
    {
        if (static::$streaming) {
            static::closeStreaming();

            return;
        }

        $element = array_pop(static::$stack);

        if (empty(static::$stack)) {
            static::$roots[] = $element;
        } else {
            static::$stack[count(static::$stack) - 1]->addChild($element);
        }
    }

    public static function leaf(string $type, array $attrs): void
    {
        $attrs = static::extractPoll($attrs);

        if (static::$streaming) {
            static::leafStreaming($type, $attrs);

            return;
        }

        $element = static::createElement($type, $attrs);

        if (empty(static::$stack)) {
            static::$roots[] = $element;
        } else {
            static::$stack[count(static::$stack) - 1]->addChild($element);
        }
    }

    public static function collect(): Element
    {
        $roots = static::$roots;
        static::reset();

        if (empty($roots)) {
            throw new \RuntimeException('No root element was built by the Blade template.');
        }

        // Single root — return directly
        if (count($roots) === 1) {
            return $roots[0];
        }

        // Multiple top-level elements — wrap in an implicit column
        $wrapper = Column::make();
        $wrapper->fill();
        foreach ($roots as $root) {
            $wrapper->addChild($root);
        }

        return $wrapper;
    }

    public static function reset(): void
    {
        static::$stack = [];
        static::$roots = [];
        static::$streaming = false;
        static::$pollIntervals = [];
        // Phase 1 — clear any leftover key-path entries between frames
        // (e.g. an error thrown mid-render that skipped the matching
        // closeStreaming pops).
        static::$keyPathStack = [];
    }

    protected static function createElement(string $type, array $attrs): Element
    {
        // Parse Tailwind classes into attribute array
        if (isset($attrs['class'])) {
            $classAttrs = TailwindParser::parse($attrs['class']);
            $attrs = array_merge($classAttrs, $attrs);
            unset($attrs['class']);
        }

        // Phase 1 — pull the key off here so applyAttributes downstream doesn't
        // see it and route it as a prop. `native-key` is the precompiled form of
        // `native:key` (the attr parser rejects ':'); accept the colon form too.
        // Element's toArray() turns the key into a stable hashed nodeId via the
        // same FNV-1a path the streaming collector uses.
        $key = $attrs['native-key'] ?? $attrs['native:key'] ?? null;
        if ($key !== null) {
            unset($attrs['native-key'], $attrs['native:key']);
        }

        $element = match ($type) {
            'column' => Column::make(),
            'row' => Row::make(),
            'stack' => Stack::make(),
            'scroll_view' => ScrollView::make(),
            'spacer' => Elements\Spacer::make(),
            'divider' => Elements\Divider::make(),
            'pressable' => Elements\Pressable::make(),
            'canvas' => Elements\Canvas::make(),
            default => ElementRegistry::resolve($type)
                ?? throw new \RuntimeException("Unknown native element type: {$type}"),
        };

        // Let plugin elements apply their own attributes
        $element->applyAttributes($attrs);

        // Phase 1 — apply `native:key` after attrs so an Element subclass
        // can't accidentally swallow it. Toggles the Element's id
        // derivation in toArray() to hash(parentKeyPath/'/'/$key).
        if ($key !== null) {
            $element->key((string) $key);
        }

        static::applyLayout($element, $attrs);
        static::applyStyle($element, $attrs);
        static::applyCallbacks($element, $attrs);
        static::applyElementProps($element, $attrs);

        // Dark mode overrides — merge into element's extra props
        $darkProps = static::buildDarkProps($attrs);
        if (! empty($darkProps)) {
            $element->mergeDarkProps($darkProps);
        }

        // Animation props — push through `setProp` so builtin elements
        // (column/row/stack/etc.) pick up `animate-duration`,
        // `animate-easing`, etc. without needing per-element wiring.
        foreach (static::buildAnimationProps($attrs) as $key => $value) {
            $element->setProp($key, $value);
        }

        // Accessibility props — same central path, so every element honors
        // `a11y-label` / `a11y-hint` even without per-element wiring (the
        // HasA11y trait covers the fluent API; setProp is idempotent when
        // an element already parsed these in applyAttributes).
        if (isset($attrs['a11y-label']) || isset($attrs['a11yLabel'])) {
            $element->setProp('a11y_label', (string) ($attrs['a11y-label'] ?? $attrs['a11yLabel']));
        }
        if (isset($attrs['a11y-hint']) || isset($attrs['a11yHint'])) {
            $element->setProp('a11y_hint', (string) ($attrs['a11y-hint'] ?? $attrs['a11yHint']));
        }

        return $element;
    }

    public static function applyLayout(Element $element, array $attrs): void
    {
        if (! empty($attrs['fill'])) {
            $element->fill();
        }
        if (! empty($attrs['fillWidth'])) {
            $element->fillWidth();
        }
        if (! empty($attrs['fillHeight'])) {
            $element->fillHeight();
        }
        if (isset($attrs['width'])) {
            $element->width($attrs['width']);
        }
        if (isset($attrs['height'])) {
            $element->height($attrs['height']);
        }
        // Padding (uniform + directional from Tailwind classes)
        $uniformPadding = isset($attrs['padding']) && ! is_array($attrs['padding']) ? (float) $attrs['padding'] : null;
        $pt = $attrs['paddingTop'] ?? null;
        $pr = $attrs['paddingRight'] ?? null;
        $pb = $attrs['paddingBottom'] ?? null;
        $pl = $attrs['paddingLeft'] ?? null;

        if ($pt !== null || $pr !== null || $pb !== null || $pl !== null) {
            $base = $uniformPadding ?? 0;
            $element->padding(
                (float) ($pt ?? $base),
                (float) ($pr ?? $base),
                (float) ($pb ?? $base),
                (float) ($pl ?? $base),
            );
        } elseif (isset($attrs['padding'])) {
            if (is_array($attrs['padding'])) {
                $element->padding(...array_map('floatval', $attrs['padding']));
            } else {
                $element->padding((float) $attrs['padding']);
            }
        }

        // Margin (uniform + directional from Tailwind classes)
        $uniformMargin = isset($attrs['margin']) && ! is_array($attrs['margin']) ? (float) $attrs['margin'] : null;
        $mt = $attrs['marginTop'] ?? null;
        $mr = $attrs['marginRight'] ?? null;
        $mb = $attrs['marginBottom'] ?? null;
        $ml = $attrs['marginLeft'] ?? null;

        if ($mt !== null || $mr !== null || $mb !== null || $ml !== null) {
            $base = $uniformMargin ?? 0;
            $element->margin(
                (float) ($mt ?? $base),
                (float) ($mr ?? $base),
                (float) ($mb ?? $base),
                (float) ($ml ?? $base),
            );
        } elseif (isset($attrs['margin'])) {
            if (is_array($attrs['margin'])) {
                $element->margin(...array_map('floatval', $attrs['margin']));
            } else {
                $element->margin((float) $attrs['margin']);
            }
        }
        if (isset($attrs['gap'])) {
            $element->gap((float) $attrs['gap']);
        }
        if (! empty($attrs['center'])) {
            $element->center();
        }
        if (! empty($attrs['safeArea'])) {
            $element->safeArea();
        }
        if (! empty($attrs['safeAreaTop'])) {
            $element->safeAreaTop();
        }
        if (! empty($attrs['safeAreaBottom'])) {
            $element->safeAreaBottom();
        }
        if (isset($attrs['flexGrow'])) {
            $element->flexGrow((float) $attrs['flexGrow']);
        }
        if (isset($attrs['flexShrink'])) {
            $element->flexShrink((float) $attrs['flexShrink']);
        }
        if (isset($attrs['flexWrap'])) {
            $element->flexWrap((int) $attrs['flexWrap']);
        }
        if (isset($attrs['aspectRatio'])) {
            $element->aspectRatio((float) $attrs['aspectRatio']);
        }
        if (isset($attrs['alignSelf'])) {
            $element->alignSelf((int) $attrs['alignSelf']);
        }
        if (isset($attrs['alignItems'])) {
            $element->alignItems((int) $attrs['alignItems']);
        }
        if (isset($attrs['justifyContent'])) {
            $element->justifyContent((int) $attrs['justifyContent']);
        }
        if (isset($attrs['positionType'])) {
            $element->positionType((int) $attrs['positionType']);
        }
        if (isset($attrs['positionTop']) || isset($attrs['positionRight'])
            || isset($attrs['positionBottom']) || isset($attrs['positionLeft'])) {
            $element->insets(
                (float) ($attrs['positionTop'] ?? 0),
                (float) ($attrs['positionRight'] ?? 0),
                (float) ($attrs['positionBottom'] ?? 0),
                (float) ($attrs['positionLeft'] ?? 0),
            );
        }
    }

    public static function applyStyle(Element $element, array $attrs): void
    {
        if (isset($attrs['bg'])) {
            $element->bg($attrs['bg']);
        }
        if (isset($attrs['borderRadius'])) {
            $element->borderRadius((float) $attrs['borderRadius']);
        }
        if (isset($attrs['borderWidth'], $attrs['borderColor'])) {
            $element->border((float) $attrs['borderWidth'], $attrs['borderColor']);
        }
        if (isset($attrs['opacity']) && ! ($attrs['opacity'] instanceof SharedValue)) {
            // SharedValue-bound opacity is routed through the prop bag
            // (`opacity_sv`) by `buildAnimationProps` — skip the style
            // setter here so the binding wins on the native side.
            $element->opacity((float) $attrs['opacity']);
        }
        if (isset($attrs['elevation'])) {
            $element->elevation((float) $attrs['elevation']);
        }
        // Liquid Glass material (1 = regular, 2 = thick). Stored as a
        // generic prop so the renderer can read it via `props.getInt`
        // — no NodeStyle binary-layout change needed.
        if (isset($attrs['glass'])) {
            $element->setProp('glass', (int) $attrs['glass']);
        }
    }

    protected static function applyCallbacks(Element $element, array $attrs): void
    {
        // Test-targeting handle (`ref="save-btn"`) — generic across all
        // element types, like the callback attrs below.
        if (isset($attrs['ref'])) {
            $element->ref((string) $attrs['ref']);
        }

        if (isset($attrs['_press'])) {
            $element->onPress($attrs['_press']);
        }
        if (isset($attrs['_longPress'])) {
            $element->onLongPress($attrs['_longPress']);
        }
        if (isset($attrs['_doubleTap'])) {
            $element->onDoubleTap($attrs['_doubleTap']);
        }
        if (isset($attrs['_change']) && method_exists($element, 'onChange')) {
            $element->onChange($attrs['_change']);
        }
        if (isset($attrs['_submit']) && method_exists($element, 'onSubmit')) {
            $element->onSubmit($attrs['_submit']);
        }
        if (isset($attrs['_dismiss']) && method_exists($element, 'onDismiss')) {
            $element->onDismiss($attrs['_dismiss']);
        }
        if (isset($attrs['_refresh']) && method_exists($element, 'onRefresh')) {
            $element->onRefresh($attrs['_refresh']);
        }
        if (isset($attrs['_endReached']) && method_exists($element, 'onEndReached')) {
            $element->onEndReached($attrs['_endReached']);
        }
        if (isset($attrs['_swipeDelete']) && method_exists($element, 'onSwipeDelete')) {
            $element->onSwipeDelete($attrs['_swipeDelete']);
        }
        if (isset($attrs['_navigate'])) {
            $element->setNavigateConfig($attrs['_navigate']);
        }
    }

    public static function applyElementProps(Element $element, array $attrs): void
    {
        if ($element instanceof ScrollView) {
            // `axis="both"` enables 2D scrolling. Falls back to the legacy
            // `horizontal` boolean when axis isn't set.
            $axis = $attrs['axis'] ?? null;
            if ($axis === 'both') {
                $element->both();
            } elseif ($axis === 'horizontal' || ! empty($attrs['horizontal'])) {
                $element->horizontal();
            }
            // 'vertical' (or unset) is the default — no method call needed.

            // Accept both kebab (`shows-indicators`) and camel
            // (`showsIndicators`) — the precompiler keeps attribute names
            // verbatim, and the rest of the API takes either form.
            if (isset($attrs['showsIndicators']) || isset($attrs['shows-indicators'])) {
                $element->showsIndicators((bool) ($attrs['showsIndicators'] ?? $attrs['shows-indicators']));
            }
        }
    }
}
