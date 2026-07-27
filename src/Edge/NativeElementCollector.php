<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Row;
use Native\Mobile\Edge\Elements\ScrollView;
use Native\Mobile\Edge\Elements\Stack;
use Native\Mobile\Edge\Enums\AlignItems;
use Native\Mobile\Edge\Enums\AlignSelf;
use Native\Mobile\Edge\Enums\JustifyContent;
use Native\Mobile\Edge\Exceptions\ComponentSlotNotSupportedException;

class NativeElementCollector
{
    protected static array $stack = [];

    protected static array $roots = [];

    protected static bool $streaming = false;

    protected static ?CallbackRegistry $callbacks = null;

    /**
     * The component whose render is currently emitting into the collector —
     * the screen at the top level, or a child component while its scope is
     * open. Child-component tags mount through it (it owns the instance
     * map), which is how nesting recurses.
     */
    protected static ?NativeComponent $owner = null;

    /**
     * Depth of open child-component render scopes. 0 = the screen's own
     * render. While > 0, view()/partial() must not hard-reset the collector
     * (the parent's tree is live in $stack/$roots) — see the guards in
     * NativeComponent::view() and NativeElementCollector::capture().
     */
    protected static int $componentDepth = 0;

    /** Recursion guard — a component that mounts itself would loop forever. */
    protected const MAX_COMPONENT_DEPTH = 32;

    /**
     * Sentinel Element returned by view()/fromView() during a child render:
     * the child's elements were already emitted in place, so there is
     * nothing for the caller to attach. Compared by identity.
     */
    protected static ?Element $scopeMarker = null;

    /**
     * Tag names the collector handles itself (createElement's match arms +
     * the precompiler's special slots). These are never child components,
     * whatever the ComponentRegistry says.
     */
    protected const COLLECTOR_BUILTIN_TYPES = [
        'column', 'row', 'stack', 'scroll_view', 'pressable', 'canvas',
        'spacer', 'divider', 'bottom_bar', 'virtual_list',
        'text', 'button', 'webview',
    ];

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

    /**
     * Stack of in-progress `<text>` frames used to capture inline runs.
     *
     * A `<text>` with nested `<text>` children must emit ordered inline runs
     * (raw text + child runs, in document order) rather than a flattened
     * string, so the renderer composes them into ONE wrapping AttributedString/
     * AnnotatedString. Because raw text is echoed (buffered) while child runs
     * are collector calls, we buffer per-`<text>` and record each segment as an
     * ordered descriptor here; the outermost close emits the whole tree through
     * open()/leaf()/close() (which already dispatch streaming vs Element-tree).
     *
     * Each frame: ['attrs' => array, 'children' => list<descriptor>].
     * Descriptor: ['attrs' => array, 'children' => list<descriptor>|null]
     * (children === null means a leaf run).
     */
    protected static array $textFrames = [];

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

    // ── Child-component scopes ───────────────────────

    /**
     * Bind the component whose render is emitting into the collector.
     * The screen's view()/partial() paths set themselves here; child
     * scopes swap it via beginComponentScope().
     */
    public static function setOwner(?NativeComponent $owner): void
    {
        static::$owner = $owner;
    }

    public static function inComponentScope(): bool
    {
        return static::$componentDepth > 0;
    }

    /**
     * Enter a child component's render scope: callbacks registered while it
     * is open land in the CHILD's registry (so dispatch resolves them to the
     * child), and any `<native:*>` component tag it contains mounts through
     * the child. Elements still emit into the parent's tree at the current
     * position — the scope swaps ownership, not the collection target.
     *
     * Returns the saved state to hand back to endComponentScope(). Always
     * pair the two in try/finally — an unbalanced scope poisons every
     * later render in the process.
     */
    public static function beginComponentScope(CallbackRegistry $callbacks, NativeComponent $owner): array
    {
        if (static::$componentDepth >= static::MAX_COMPONENT_DEPTH) {
            throw new \RuntimeException(
                'Child component nesting exceeded '.static::MAX_COMPONENT_DEPTH
                .' levels — is a component mounting itself (directly or via a cycle)?'
            );
        }

        $saved = ['callbacks' => static::$callbacks, 'owner' => static::$owner];

        static::$componentDepth++;
        static::$callbacks = $callbacks;
        static::$owner = $owner;

        return $saved;
    }

    public static function endComponentScope(array $saved): void
    {
        static::$componentDepth--;
        static::$callbacks = $saved['callbacks'];
        static::$owner = $saved['owner'];
    }

    /** The identity-compared "already emitted in place" sentinel. */
    public static function scopeMarker(): Element
    {
        return static::$scopeMarker ??= Column::make();
    }

    /**
     * Attach a programmatically-built Element at the current tree position —
     * used when a child component's render() returns an Element instead of
     * a Blade view.
     */
    public static function attachElement(Element $element): void
    {
        if (static::$streaming) {
            throw new \RuntimeException(
                'A child component returned an Element from render() during a streaming render — '
                .'child components must render Blade views under streaming.'
            );
        }

        if (empty(static::$stack)) {
            static::$roots[] = $element;
        } else {
            static::$stack[count(static::$stack) - 1]->addChild($element);
        }
    }

    /**
     * Collect a detached subtree while a component scope is open, WITHOUT
     * resetting the live parent tree: swaps the working state out, runs the
     * render closure, and restores it. This is what keeps partial() safe to
     * call from inside a child component.
     */
    public static function capture(\Closure $render): Element
    {
        $saved = [static::$stack, static::$roots, static::$textFrames, static::$keyPathStack];
        static::$stack = [];
        static::$roots = [];
        static::$textFrames = [];
        static::$keyPathStack = [];

        try {
            $render();
            $roots = static::$roots;
        } finally {
            [static::$stack, static::$roots, static::$textFrames, static::$keyPathStack] = $saved;
        }

        return static::wrapRoots($roots);
    }

    /**
     * True when an emitted tag should mount a registered child component:
     * never for collector builtins or registered element types (elements
     * always win), and only while a component render context is bound.
     */
    protected static function isComponentTag(string $type): bool
    {
        return static::$owner !== null
            && ! in_array($type, static::COLLECTOR_BUILTIN_TYPES, true)
            && ! ElementRegistry::has($type)
            && ComponentRegistry::has($type);
    }

    /** Mount a child component at the current tree position. */
    protected static function mountComponent(string $type, array $attrs): void
    {
        $tag = str_replace('_', '-', $type);

        if (static::$owner === null) {
            throw new \RuntimeException(
                "Component tag <native:{$tag}> can only render inside a NativeComponent render."
            );
        }

        static::$owner->mountChildComponent($tag, $attrs);
    }

    /**
     * Reject slot content: the top of the stack being a ComponentTagFrame
     * means we are between a component tag's open and close.
     */
    protected static function guardAgainstComponentSlot(string $type): void
    {
        $top = end(static::$stack);

        if ($top instanceof ComponentTagFrame) {
            throw new ComponentSlotNotSupportedException($top->tag, $type);
        }
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
            $props = static::buildDarkProps($attrs)
                + static::buildGradientProps($attrs)
                + static::buildAnimationProps($attrs);
            $onPress = static::resolveOnPress($attrs);
            $onLongPress = static::resolveOnLongPress($attrs);

            // Double-tap rides the props dict (not a dedicated node field).
            if (($doubleTap = static::resolveOnDoubleTap($attrs)) !== 0) {
                $props['on_double_tap'] = $doubleTap;
            }

            // Press-down/up ride the props dict too (see resolveOnPressDown).
            if (($pressDown = static::resolveOnPressDown($attrs)) !== 0) {
                $props['on_press_down'] = $pressDown;
            }
            if (($pressUp = static::resolveOnPressUp($attrs)) !== 0) {
                $props['on_press_up'] = $pressUp;
            }

            // ScrollView needs overflow: scroll so the flex layout doesn't constrain children
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
            $darkProps = static::buildDarkProps($attrs) + static::buildGradientProps($attrs);
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
            $props = static::buildDarkProps($attrs)
                + static::buildGradientProps($attrs)
                + static::buildAnimationProps($attrs);
            $onPress = static::resolveOnPress($attrs);
            $onLongPress = static::resolveOnLongPress($attrs);

            // Double-tap rides the props dict (not a dedicated node field).
            if (($doubleTap = static::resolveOnDoubleTap($attrs)) !== 0) {
                $props['on_double_tap'] = $doubleTap;
            }

            // Press-down/up ride the props dict too (see resolveOnPressDown).
            if (($pressDown = static::resolveOnPressDown($attrs)) !== 0) {
                $props['on_press_down'] = $pressDown;
            }
            if (($pressUp = static::resolveOnPressUp($attrs)) !== 0) {
                $props['on_press_up'] = $pressUp;
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
            $darkProps = static::buildDarkProps($attrs) + static::buildGradientProps($attrs);
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
        if (isset($attrs['alignSelf']) && ($alignSelf = AlignSelf::parse($attrs['alignSelf'])) !== null) {
            $layout['align_self'] = $alignSelf;
        }
        if (isset($attrs['alignItems']) && ($alignItems = AlignItems::parse($attrs['alignItems'])) !== null) {
            $layout['align_items'] = $alignItems;
        }
        if (isset($attrs['justifyContent']) && ($justifyContent = JustifyContent::parse($attrs['justifyContent'])) !== null) {
            $layout['justify_content'] = $justifyContent;
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
     *   - `animate-delay`    (ms float, start offset; loop mode staggers the cycle)
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
        if (isset($attrs['animate-delay'])) {
            $props['animate-delay'] = (float) $attrs['animate-delay'];
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
     * Build linear-gradient props from the `gradient` attribute key
     * (`bg-gradient-to-* from-* via-* to-*`).
     *
     * These ride the props bag rather than NodeStyle because the style block
     * is a fixed-layout region of the packed binary node — there is no room
     * for a variable-length stop list without changing the wire format on
     * both decoders. `dark_bg_color` takes the same route for the same reason.
     *
     * Emitted only when there is an axis AND at least two stops, so a partial
     * declaration (a stray `from-black` with no direction) stays inert instead
     * of painting something the author never asked for. When present, the
     * native style modifiers draw this INSTEAD of `bg_color`.
     *
     * @return array{gradient_dx?: float, gradient_dy?: float, gradient_stops?: string}
     */
    public static function buildGradientProps(array $attrs): array
    {
        if (! isset($attrs['gradient']) || ! is_array($attrs['gradient'])) {
            return [];
        }

        $gradient = $attrs['gradient'];
        $direction = $gradient['direction'] ?? null;

        $stops = array_values(array_filter([
            $gradient['from'] ?? null,
            $gradient['via'] ?? null,
            $gradient['to'] ?? null,
        ]));

        if (! is_array($direction) || count($stops) < 2) {
            return [];
        }

        return [
            'gradient_dx' => (float) $direction[0],
            'gradient_dy' => (float) $direction[1],
            // Comma-joined so the stop list stays a single string prop; the
            // native side splits it. Two or three `#AARRGGBB` entries.
            'gradient_stops' => implode(',', $stops),
        ];
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

    /**
     * Press-down / press-up callback ids. Like double-tap these travel in
     * the props dict (`on_press_down` / `on_press_up`) and reuse the PRESS
     * wire event — the callback id alone routes to the handler — so no
     * `nphp_node_*` signature or binary wire-format change. Returns 0 when
     * the corresponding `@tapDown` / `@tapUp` attr is not set.
     */
    protected static function resolveOnPressDown(array $attrs): int
    {
        if (isset($attrs['_pressDown']) && static::$callbacks) {
            return static::$callbacks->register($attrs['_pressDown']);
        }

        return 0;
    }

    protected static function resolveOnPressUp(array $attrs): int
    {
        if (isset($attrs['_pressUp']) && static::$callbacks) {
            return static::$callbacks->register($attrs['_pressUp']);
        }

        return 0;
    }

    // ── Public methods (delegates to streaming or legacy) ───

    public static function open(string $type, array $attrs): void
    {
        $attrs = static::extractPoll($attrs);

        // A registered child-component tag: push a marker frame; the
        // matching close() mounts the child (and anything collected in
        // between is rejected slot content). Resolved at runtime so
        // compiled views survive registry changes.
        if (static::isComponentTag($type)) {
            static::$stack[] = new ComponentTagFrame(str_replace('_', '-', $type), $attrs);

            return;
        }

        static::guardAgainstComponentSlot($type);
        $attrs = static::stripEventBindings($attrs);

        if (static::$streaming) {
            static::openStreaming($type, $attrs);

            return;
        }

        $element = static::createElement($type, $attrs);
        static::$stack[] = $element;
    }

    /**
     * Drop compiled `@event=` bindings (`_event-*` attrs) from a PLAIN
     * element's attrs — they only mean something on a child-component tag,
     * and components are resolved at runtime so the compiler can't tell
     * the two apart. Leaving them in would leak junk props to the wire.
     */
    protected static function stripEventBindings(array $attrs): array
    {
        foreach ($attrs as $key => $value) {
            if (str_starts_with($key, '_event-')) {
                unset($attrs[$key]);
            }
        }

        return $attrs;
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
        // The matching open() pushed a component frame — mount the child
        // here, at the position the tag occupies in the parent's tree.
        if (end(static::$stack) instanceof ComponentTagFrame) {
            $frame = array_pop(static::$stack);
            static::mountComponent(str_replace('-', '_', $frame->tag), $frame->attrs);

            return;
        }

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

        // Self-closing child-component tag — mount immediately in place.
        if (static::isComponentTag($type)) {
            static::mountComponent($type, $attrs);

            return;
        }

        static::guardAgainstComponentSlot($type);
        $attrs = static::stripEventBindings($attrs);

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

    // ── Inline text runs (<text> with nested <text>) ─────

    /**
     * Begin capturing a `<text>` element's slot as ordered inline runs.
     * Flushes the parent `<text>`'s pending raw text as a run first (so a
     * nested run lands in document order), then buffers this element's content.
     */
    public static function textOpen(array $attrs): void
    {
        if (! empty(static::$textFrames)) {
            static::captureRawRunIntoTopFrame();
        }

        static::$textFrames[] = ['attrs' => $attrs, 'children' => []];
        ob_start();
    }

    /**
     * Finish a `<text>` element. If it accumulated child runs it emits as a
     * container of ordered run-nodes (own text empty); otherwise it stays a
     * leaf with today's trimmed-string behavior. Nested elements become a run
     * of their parent; the outermost one emits the whole tree via open()/leaf()/
     * close() so streaming and the Element-tree path both work unchanged.
     */
    public static function textClose(): void
    {
        $buffer = ob_get_clean();
        $frame = array_pop(static::$textFrames);
        $isNested = ! empty(static::$textFrames);

        if (! empty($frame['children'])) {
            // Container: its own trailing buffer is a run; own text stays empty.
            $tail = static::normalizeRunText($buffer);
            if ($tail !== '') {
                $frame['children'][] = ['attrs' => ['text' => $tail], 'children' => null];
            }
            $descriptor = ['attrs' => $frame['attrs'], 'children' => $frame['children']];
        } else {
            // Childless <text>: a RUN when nested (preserve meaningful edge
            // spaces), but a top-level leaf keeps today's exact trimmed +
            // whitespace-collapsed string so nothing else regresses.
            $attrs = $frame['attrs'];
            $text = $isNested ? static::normalizeRunText($buffer) : static::normalizeLeafText($buffer);
            if ($text !== '') {
                $attrs['text'] = $text;
            }
            $descriptor = ['attrs' => $attrs, 'children' => null];
        }

        if ($isNested) {
            // Nested: this <text> is a run of its parent. Resume the parent's
            // buffer for whatever raw text follows this child.
            $top = count(static::$textFrames) - 1;
            static::$textFrames[$top]['children'][] = $descriptor;
            ob_start();
        } else {
            static::emitTextDescriptor($descriptor);
        }
    }

    /** Flush the currently-buffered raw text as a run of the top frame. */
    protected static function captureRawRunIntoTopFrame(): void
    {
        $text = static::normalizeRunText(ob_get_clean());
        if ($text !== '') {
            $top = count(static::$textFrames) - 1;
            static::$textFrames[$top]['children'][] = ['attrs' => ['text' => $text], 'children' => null];
        }
    }

    /**
     * Whitespace policy for a raw run: drop pure-whitespace segments (inter-tag
     * newlines/indentation are formatting, not content) so multiline markup
     * doesn't inject spurious spaces; collapse internal runs of whitespace to a
     * single space but PRESERVE meaningful leading/trailing spaces (a run may be
     * `" / "`, or prose like `"Use "` before an inline chip).
     */
    protected static function normalizeRunText(string $raw): string
    {
        $s = html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8');
        if (trim($s) === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $s);
    }

    /** Leaf `<text>` text: today's exact behavior (trim + collapse). */
    protected static function normalizeLeafText(string $raw): string
    {
        return preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8')));
    }

    /**
     * Emit a text descriptor tree via the mode-agnostic open()/leaf()/close(),
     * so it becomes an Element subtree (non-streaming) or streams (streaming)
     * exactly like any other node.
     */
    protected static function emitTextDescriptor(array $descriptor): void
    {
        if ($descriptor['children'] === null) {
            static::leaf('text', $descriptor['attrs']);

            return;
        }

        static::open('text', $descriptor['attrs']);
        foreach ($descriptor['children'] as $child) {
            static::emitTextDescriptor($child);
        }
        static::close();
    }

    public static function collect(): Element
    {
        $roots = static::$roots;
        static::reset();

        return static::wrapRoots($roots);
    }

    /** Shared root normalization for collect() and capture(). */
    protected static function wrapRoots(array $roots): Element
    {
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
        static::$textFrames = [];
        // Phase 1 — clear any leftover key-path entries between frames
        // (e.g. an error thrown mid-render that skipped the matching
        // closeStreaming pops).
        static::$keyPathStack = [];
        // Reset is only reachable at screen level (child scopes route
        // through capture()), so the render-context bookkeeping can be
        // cleared too — the screen's view()/partial() rebinds it right
        // after. Guards against a scope leaked by an aborted frame.
        static::$owner = null;
        static::$componentDepth = 0;
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
            // Inline `<native:bottom-bar>` — bottom-pinned content (chat input,
            // search bar, …). Hoisted out of the screen tree to the
            // native-chrome root in NativeComponent::wrapWithNativeChrome and
            // pinned via `.safeAreaInset(.bottom)` (iOS) / `Scaffold(bottomBar=)`
            // (Android), which keeps it above the software keyboard natively.
            'bottom_bar' => Elements\BottomBar::make(),
            default => ElementRegistry::resolve($type)
                ?? throw new \RuntimeException("Unknown native element type: {$type}"),
        };

        // Let plugin elements apply their own attributes
        $element->applyAttributes($attrs);

        // Inside a child component's render scope, callbacks belong to the
        // CHILD's registry — pin it on the element so toArray() registers
        // @tap / native:model handlers there, and dispatch resolves them
        // back to the child instance. At screen level (depth 0) the passed
        // registry already is the screen's, so nothing needs pinning.
        if (static::$componentDepth > 0 && static::$callbacks !== null) {
            $element->ownCallbacks(static::$callbacks);
        }

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

        // Gradient props — same central `setProp` path as animation below, so
        // `bg-gradient-to-*` works on every element type without per-element
        // wiring.
        foreach (static::buildGradientProps($attrs) as $key => $value) {
            $element->setProp($key, $value);
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
            $element->alignSelf($attrs['alignSelf']);
        }
        if (isset($attrs['alignItems'])) {
            $element->alignItems($attrs['alignItems']);
        }
        if (isset($attrs['justifyContent'])) {
            $element->justifyContent($attrs['justifyContent']);
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
        // Text selection opt-in (`select-text` / `select-none`). Generic across
        // element types — set on any node to make its subtree selectable (or
        // exempt one inside a selectable ancestor). Renderers read it via
        // `props.getInt('selectable')`: 1 = enable, 0 = disable.
        if (isset($attrs['selectable'])) {
            $element->setProp('selectable', (int) $attrs['selectable']);
        }
        // Scroll anchoring. `scroll-anchor="bottom"` makes a scroll_view / list
        // stick to the bottom (chat behavior): opens at the latest item and
        // auto-scrolls on new content when the user is already near the bottom.
        if (isset($attrs['scroll-anchor'])) {
            $element->setProp('scroll_anchor', (string) $attrs['scroll-anchor']);
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
        if (isset($attrs['_pressDown'])) {
            $element->onPressDown($attrs['_pressDown']);
        }
        if (isset($attrs['_pressUp'])) {
            $element->onPressUp($attrs['_pressUp']);
        }
        if (isset($attrs['_change']) && method_exists($element, 'onChange')) {
            $element->onChange($attrs['_change']);
        }
        if (isset($attrs['_selectionChange']) && method_exists($element, 'onSelectionChange')) {
            $element->onSelectionChange($attrs['_selectionChange']);
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
        if (isset($attrs['_swipe']) && method_exists($element, 'onSwipe')) {
            $element->onSwipe($attrs['_swipe']);
        }
        if (isset($attrs['_pinchEnd']) && method_exists($element, 'onPinchEnd')) {
            $element->onPinchEnd($attrs['_pinchEnd']);
        }
        if (isset($attrs['_navigated']) && method_exists($element, 'onNavigated')) {
            $element->onNavigated($attrs['_navigated']);
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

            // Bring a child index into view: `:auto-scroll-to="$unreadIndex"`.
            // Indexes the scroll-view's OWN children, so content wrapped in a
            // <column> addresses the column, not the rows inside it. Takes
            // precedence over `scroll-anchor="bottom"` when both are set — the
            // author named a specific child. Mirrors the fluent
            // ScrollView::autoScrollTo() so both authoring styles behave alike.
            if (isset($attrs['autoScrollTo']) || isset($attrs['auto-scroll-to'])) {
                $element->autoScrollTo((int) ($attrs['autoScrollTo'] ?? $attrs['auto-scroll-to']));
            }
        }
    }
}
