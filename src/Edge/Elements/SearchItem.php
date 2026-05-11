<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Wire-side `search_item` node, emitted as a child of `native_root_tabs`
 * (parallel to `bottom_nav_item`). The framework converts each entry
 * returned from `searchItems()` / `onSearchQuery()` into one of these
 * via `SearchItem::from(...)`.
 *
 * Items can take three shapes; this element carries a `kind` prop so
 * the iOS / Android renderers can dispatch the right row UI:
 *
 *   - `kind: "string"` — props: `{ value }`. Default Text row, no tap.
 *   - `kind: "object"` — props: `{ title, subtitle?, leading?, trailing? }`.
 *                        Tap fires the node's standard `on_press` (set
 *                        via `setNavigateConfig` for url-form or
 *                        `onPress` for method-form).
 *   - `kind: "element"` — wraps a user-provided `Element` as its
 *                         child; the renderer just defers to `NodeView`
 *                         and the element's own onPress chain.
 *
 * Goes through the regular wire format — no custom prop encoding
 * required, the existing FlatBuffer machinery handles it.
 */
class SearchItem extends Element
{
    protected string $type = 'search_item';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    /**
     * Normalize one user-provided entry. Returns null for inputs we
     * can't translate so callers can skip them.
     */
    public static function from(mixed $input): ?self
    {
        if (is_string($input)) {
            return static::string($input);
        }

        if ($input instanceof Element) {
            return static::element($input);
        }

        if (is_array($input)) {
            return static::object($input);
        }

        return null;
    }

    public static function string(string $value): self
    {
        $item = new self;
        $item->props['kind'] = 'string';
        $item->props['value'] = $value;

        return $item;
    }

    public static function object(array $data): self
    {
        $item = new self;
        $item->props['kind'] = 'object';

        foreach (['title', 'subtitle', 'leading', 'trailing'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $item->props[$key] = $data[$key];
            }
        }

        // Tap behavior. `method` wins over `url` so a dev can fall back
        // to a callback even on items that also have a logical URL.
        // Both paths reuse the existing per-element `onPress` /
        // `setNavigateConfig` machinery — no per-item callback ledger
        // needed at the search level.
        if (isset($data['method']) && is_string($data['method'])) {
            $expression = $data['method'];
            if (isset($data['args']) && is_array($data['args'])) {
                $argLiteral = json_encode(array_values($data['args']));
                $expression .= '('.trim((string) $argLiteral, '[]').')';
            }
            $item->onPress($expression);
        } elseif (isset($data['url']) && is_string($data['url']) && $data['url'] !== '') {
            $item->setNavigateConfig([
                'type' => 'navigate',
                'uri' => $data['url'],
                'data' => [],
                'transition' => 'slide_from_right',
            ]);
        }

        return $item;
    }

    public static function element(Element $element): self
    {
        $item = new self;
        $item->props['kind'] = 'element';
        $item->addChild($element);

        return $item;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
