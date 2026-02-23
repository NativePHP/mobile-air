<?php

namespace Native\Mobile\Edge;

abstract class Element
{
    protected string $type;

    protected ?int $nodeId = null;

    protected array $layout = [];

    protected array $style = [];

    protected ?string $pressMethod = null;

    protected ?string $longPressMethod = null;

    protected array $children = [];

    // ── Attribute hydration ──────────────────────────────

    /**
     * Apply Blade attributes to this element.
     *
     * Plugin elements override this to map Blade attrs to their props.
     * Built-in elements use the instanceof chain in NativeElementCollector instead.
     */
    public function applyAttributes(array $attrs): void
    {
        // No-op by default — built-in elements use applyElementProps()
    }

    // ── Tree building ─────────────────────────────────

    public function addChild(Element $child): static
    {
        $this->children[] = $child;

        return $this;
    }

    // ── Layout methods ───────────────────────────────

    public function width(float|string $value): static
    {
        $this->layout['width'] = $value;

        return $this;
    }

    public function height(float|string $value): static
    {
        $this->layout['height'] = $value;

        return $this;
    }

    public function fill(): static
    {
        $this->layout['width'] = 'fill';
        $this->layout['height'] = 'fill';

        return $this;
    }

    public function fillWidth(): static
    {
        $this->layout['width'] = 'fill';

        return $this;
    }

    public function fillHeight(): static
    {
        $this->layout['height'] = 'fill';

        return $this;
    }

    public function padding(float ...$values): static
    {
        if (count($values) === 1) {
            $this->layout['padding'] = $values[0];
        } elseif (count($values) === 4) {
            $this->layout['padding'] = $values;
        }

        return $this;
    }

    public function margin(float ...$values): static
    {
        if (count($values) === 1) {
            $this->layout['margin'] = $values[0];
        } elseif (count($values) === 4) {
            $this->layout['margin'] = $values;
        }

        return $this;
    }

    public function gap(float $value): static
    {
        $this->layout['gap'] = $value;

        return $this;
    }

    public function flexGrow(float $value): static
    {
        $this->layout['flex_grow'] = $value;

        return $this;
    }

    public function flexShrink(float $value): static
    {
        $this->layout['flex_shrink'] = $value;

        return $this;
    }

    public function alignSelf(int $value): static
    {
        $this->layout['align_self'] = $value;

        return $this;
    }

    public function alignItems(int $value): static
    {
        $this->layout['align_items'] = $value;

        return $this;
    }

    public function justifyContent(int $value): static
    {
        $this->layout['justify_content'] = $value;

        return $this;
    }

    public function center(): static
    {
        $this->layout['align_items'] = 1;
        $this->layout['justify_content'] = 1;

        return $this;
    }

    public function safeArea(): static
    {
        $this->layout['safe_area'] = 1;

        return $this;
    }

    // ── Style methods ────────────────────────────────

    public function bg(string $color): static
    {
        $this->style['bg_color'] = $color;

        return $this;
    }

    public function borderRadius(float $value): static
    {
        $this->style['border_radius'] = $value;

        return $this;
    }

    public function border(float $width, string $color): static
    {
        $this->style['border_width'] = $width;
        $this->style['border_color'] = $color;

        return $this;
    }

    public function opacity(float $value): static
    {
        $this->style['opacity'] = $value;

        return $this;
    }

    public function elevation(float $value): static
    {
        $this->style['elevation'] = $value;

        return $this;
    }

    // ── Node-level events ────────────────────────────

    public function onPress(string $method): static
    {
        $this->pressMethod = $method;

        return $this;
    }

    public function onLongPress(string $method): static
    {
        $this->longPressMethod = $method;

        return $this;
    }

    // ── Resolution ───────────────────────────────────

    public function toArray(CallbackRegistry $registry, int &$nextId = 1): array
    {
        $node = [
            'id' => $this->nodeId ?? $nextId++,
            'type' => $this->type,
        ];

        if (! empty($this->layout)) {
            $node['layout'] = $this->layout;
        }

        if (! empty($this->style)) {
            $node['style'] = $this->style;
        }

        $props = $this->resolveProps($registry);
        if (! empty($props)) {
            $node['props'] = $props;
        }

        if ($this->pressMethod !== null) {
            $node['on_press'] = $registry->register($this->pressMethod);
        }

        if ($this->longPressMethod !== null) {
            $node['on_long_press'] = $registry->register($this->longPressMethod);
        }

        if (! empty($this->children)) {
            $node['children'] = array_map(
                fn (Element $child) => $child->toArray($registry, $nextId),
                $this->children
            );
        }

        return $node;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
