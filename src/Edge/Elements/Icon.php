<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Icon\MaterialSymbol;
use Native\Mobile\Icon\SFSymbol;

class Icon extends Element
{
    protected string $type = 'icon';

    protected array $iconProps = [];

    private ?string $shared = null;
    private SFSymbol|string|null $sfOverride = null;
    private MaterialSymbol|string|null $materialOverride = null;

    public static function make(
        ?string $name = null,
        SFSymbol|string|null $sf = null,
        MaterialSymbol|string|null $material = null,
    ): static {
        $el = new static;

        return $el->name($name, $sf, $material);
    }

    public function applyAttributes(array $attrs): void
    {
        // Blade `:sf="SF::Bell"` / `:material="Material::Bell"` binds the
        // enum case directly — accept either an enum instance or a raw
        // string. The plain `name` attr is the cross-platform fallback.
        if (isset($attrs['name']))     { $this->name($attrs['name']); }
        if (isset($attrs['sf']))       { $this->name(sf: $attrs['sf']); }
        if (isset($attrs['material'])) { $this->name(material: $attrs['material']); }

        if (isset($attrs['size']))  { $this->size((float) $attrs['size']); }
        if (isset($attrs['color'])) { $this->color($attrs['color']); }

        if (isset($attrs['dark-color']) || isset($attrs['darkColor'])) {
            $this->darkColor($attrs['dark-color'] ?? $attrs['darkColor']);
        }
    }

    /**
     * Set the icon. All three args are nullable so call sites pick
     * whichever combination they need:
     *
     *   Icon::make('home')                            // shared name
     *   Icon::make(sf: SF::House, material: Material::Home)
     *   Icon::make('share', sf: SF::SquareAndArrowUp) // shared + iOS override
     *
     * The `material` slot accepts either a `Material` (filled) or
     * `MaterialOutlined` enum case — the variant is forwarded to the
     * renderer via the `material_variant` wire prop.
     */
    public function name(
        ?string $name = null,
        SFSymbol|string|null $sf = null,
        MaterialSymbol|string|null $material = null,
    ): static {
        if ($name !== null)     { $this->shared = $name; }
        if ($sf !== null)       { $this->sfOverride = $sf; }
        if ($material !== null) { $this->materialOverride = $material; }

        return $this;
    }

    public function size(float $size): static
    {
        $this->iconProps['size'] = $size;

        return $this;
    }

    public function color(string $color): static
    {
        $this->iconProps['color'] = $color;

        return $this;
    }

    public function darkColor(string $color): static
    {
        $this->iconProps['dark_color'] = $color;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->iconProps;

        $resolved = IconResolver::resolve($this->shared, $this->sfOverride, $this->materialOverride);
        if ($resolved['icon'] !== null) {
            $props['name'] = $resolved['icon'];
            if ($resolved['variant'] !== null) {
                $props['material_variant'] = $resolved['variant'];
            }
        }

        return $props;
    }
}
