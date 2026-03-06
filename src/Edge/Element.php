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

    protected ?array $navigateConfig = null;

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
        $this->layout['padding'] = match (count($values)) {
            1 => $values[0],
            2 => [$values[0], $values[1], $values[0], $values[1]],
            3 => [$values[0], $values[1], $values[2], $values[1]],
            4 => $values,
            default => $this->layout['padding'] ?? 0,
        };

        return $this;
    }

    public function margin(float ...$values): static
    {
        $this->layout['margin'] = match (count($values)) {
            1 => $values[0],
            2 => [$values[0], $values[1], $values[0], $values[1]],
            3 => [$values[0], $values[1], $values[2], $values[1]],
            4 => $values,
            default => $this->layout['margin'] ?? 0,
        };

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

    public function setNavigateConfig(array $config): static
    {
        $this->navigateConfig = $config;

        return $this;
    }

    // ── Streaming getters ────────────────────────────

    public function getType(): string
    {
        return $this->type;
    }

    public function getLayout(): array
    {
        return $this->layout;
    }

    public function getStyle(): array
    {
        return $this->style;
    }

    public function getPressCallbackId(CallbackRegistry $registry): int
    {
        if ($this->navigateConfig !== null) {
            $navKey = $registry->registerNavigation($this->navigateConfig);

            return $registry->register("__navigate('{$navKey}')");
        }

        if ($this->pressMethod !== null) {
            return $registry->register($this->pressMethod);
        }

        return 0;
    }

    public function getLongPressCallbackId(CallbackRegistry $registry): int
    {
        if ($this->longPressMethod !== null) {
            return $registry->register($this->longPressMethod);
        }

        return 0;
    }

    public function getResolvedProps(CallbackRegistry $registry): array
    {
        return $this->resolveProps($registry);
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

        if ($this->navigateConfig !== null) {
            $navKey = $registry->registerNavigation($this->navigateConfig);
            $node['on_press'] = $registry->register("__navigate('{$navKey}')");
        }

        if (! empty($this->children)) {
            $node['children'] = array_map(
                function (Element $child) use ($registry, &$nextId) {
                    return $child->toArray($registry, $nextId);
                },
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
