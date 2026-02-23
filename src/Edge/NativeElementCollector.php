<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Row;
use Native\Mobile\Edge\Elements\ScrollView;
use Native\Mobile\Edge\Elements\Stack;

class NativeElementCollector
{
    protected static array $stack = [];

    protected static ?Element $root = null;

    public static function open(string $type, array $attrs): void
    {
        $element = static::createElement($type, $attrs);
        static::$stack[] = $element;
    }

    public static function close(): void
    {
        $element = array_pop(static::$stack);

        if (empty(static::$stack)) {
            static::$root = $element;
        } else {
            static::$stack[count(static::$stack) - 1]->addChild($element);
        }
    }

    public static function leaf(string $type, array $attrs): void
    {
        $element = static::createElement($type, $attrs);

        if (empty(static::$stack)) {
            static::$root = $element;
        } else {
            static::$stack[count(static::$stack) - 1]->addChild($element);
        }
    }

    public static function collect(): Element
    {
        $root = static::$root;
        static::reset();

        if ($root === null) {
            throw new \RuntimeException('No root element was built by the Blade template.');
        }

        return $root;
    }

    public static function reset(): void
    {
        static::$stack = [];
        static::$root = null;
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
            default => ElementRegistry::resolve($type)
                ?? throw new \RuntimeException("Unknown native element type: {$type}"),
        };

        // Let plugin elements apply their own attributes
        $element->applyAttributes($attrs);

        static::applyLayout($element, $attrs);
        static::applyStyle($element, $attrs);
        static::applyCallbacks($element, $attrs);
        static::applyElementProps($element, $attrs);

        return $element;
    }

    protected static function applyLayout(Element $element, array $attrs): void
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
    }

    protected static function applyStyle(Element $element, array $attrs): void
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
    }

    protected static function applyElementProps(Element $element, array $attrs): void
    {
        if ($element instanceof ScrollView) {
            if (! empty($attrs['horizontal'])) {
                $element->horizontal();
            }
            if (isset($attrs['showsIndicators'])) {
                $element->showsIndicators((bool) $attrs['showsIndicators']);
            }
        }
    }
}
