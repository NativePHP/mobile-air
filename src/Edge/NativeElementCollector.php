<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Elements;
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

    // ── Streaming methods (write directly to C) ──────

    public static function openStreaming(string $type, array $attrs): void
    {
        if (isset($attrs['class'])) {
            $classAttrs = TailwindParser::parse($attrs['class']);
            $attrs = array_merge($classAttrs, $attrs);
            unset($attrs['class']);
        }

        $builtinTypes = ['column', 'row', 'stack', 'scroll_view', 'pressable', 'canvas'];

        if (in_array($type, $builtinTypes, true)) {
            $layout = static::buildLayoutArray($attrs);
            $style = static::buildStyleArray($attrs);
            $darkProps = static::buildDarkProps($attrs);
            $onPress = static::resolveOnPress($attrs);
            $onLongPress = static::resolveOnLongPress($attrs);

            // ScrollView needs overflow: scroll so Yoga doesn't constrain children
            if ($type === 'scroll_view' && ! isset($layout['overflow'])) {
                $layout['overflow'] = 2;
            }

            nphp_node_open(
                $type,
                ! empty($layout) ? $layout : null,
                ! empty($style) ? $style : null,
                ! empty($darkProps) ? $darkProps : null,
                $onPress,
                $onLongPress,
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
            );
        }
    }

    public static function closeStreaming(): void
    {
        nphp_node_close();
    }

    public static function leafStreaming(string $type, array $attrs): void
    {
        if (isset($attrs['class'])) {
            $classAttrs = TailwindParser::parse($attrs['class']);
            $attrs = array_merge($classAttrs, $attrs);
            unset($attrs['class']);
        }

        $builtinTypes = ['column', 'row', 'stack', 'scroll_view', 'pressable', 'canvas'];

        if (in_array($type, $builtinTypes, true)) {
            $layout = static::buildLayoutArray($attrs);
            $style = static::buildStyleArray($attrs);
            $darkProps = static::buildDarkProps($attrs);
            $onPress = static::resolveOnPress($attrs);
            $onLongPress = static::resolveOnLongPress($attrs);

            nphp_node_leaf(
                $type,
                ! empty($layout) ? $layout : null,
                ! empty($style) ? $style : null,
                ! empty($darkProps) ? $darkProps : null,
                $onPress,
                $onLongPress,
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
                (float) ($attrs['positionTop']    ?? 0),
                (float) ($attrs['positionRight']  ?? 0),
                (float) ($attrs['positionBottom'] ?? 0),
                (float) ($attrs['positionLeft']   ?? 0),
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
            $style['opacity'] = (float) $attrs['opacity'];
        }
        if (isset($attrs['elevation'])) {
            $style['elevation'] = (float) $attrs['elevation'];
        }

        return $style;
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

    // ── Public methods (delegates to streaming or legacy) ───

    public static function open(string $type, array $attrs): void
    {
        if (static::$streaming) {
            static::openStreaming($type, $attrs);

            return;
        }

        $element = static::createElement($type, $attrs);
        static::$stack[] = $element;
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
    }

    protected static function createElement(string $type, array $attrs): Element
    {
        // Parse Tailwind classes into attribute array
        if (isset($attrs['class'])) {
            $classAttrs = TailwindParser::parse($attrs['class']);
            $attrs = array_merge($classAttrs, $attrs);
            unset($attrs['class']);
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

        static::applyLayout($element, $attrs);
        static::applyStyle($element, $attrs);
        static::applyCallbacks($element, $attrs);
        static::applyElementProps($element, $attrs);

        // Dark mode overrides — merge into element's extra props
        $darkProps = static::buildDarkProps($attrs);
        if (! empty($darkProps)) {
            $element->mergeDarkProps($darkProps);
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
                (float) ($attrs['positionTop']    ?? 0),
                (float) ($attrs['positionRight']  ?? 0),
                (float) ($attrs['positionBottom'] ?? 0),
                (float) ($attrs['positionLeft']   ?? 0),
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
        if (isset($attrs['opacity'])) {
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
        if (isset($attrs['_press'])) {
            $element->onPress($attrs['_press']);
        }
        if (isset($attrs['_longPress'])) {
            $element->onLongPress($attrs['_longPress']);
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

            if (isset($attrs['showsIndicators'])) {
                $element->showsIndicators((bool) $attrs['showsIndicators']);
            }
        }
    }
}
