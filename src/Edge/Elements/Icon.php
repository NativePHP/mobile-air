<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Icon\IosSymbol;

class Icon extends Element
{
    protected string $type = 'icon';

    protected array $iconProps = [];

    private ?string $shared = null;
    private IosSymbol|string|null $iosOverride = null;
    private AndroidSymbol|string|null $androidOverride = null;

    public static function make(
        ?string $name = null,
        IosSymbol|string|null $ios = null,
        AndroidSymbol|string|null $android = null,
    ): static {
        $el = new static;

        return $el->name($name, $ios, $android);
    }

    public function applyAttributes(array $attrs): void
    {
        // Blade `:ios="Ios::Bell"` / `:android="Android::Bell"` binds the
        // enum case directly — accept either an enum instance or a raw
        // string. The plain `name` attr is the cross-platform fallback.
        if (isset($attrs['name']))    { $this->name($attrs['name']); }
        if (isset($attrs['ios']))     { $this->name(ios: $attrs['ios']); }
        if (isset($attrs['android'])) { $this->name(android: $attrs['android']); }

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
     *   Icon::make('home')                              // shared name
     *   Icon::make(ios: Ios::House, android: Android::Home)
     *   Icon::make('share', ios: Ios::SquareAndArrowUp) // shared + iOS override
     *
     * The `android` slot accepts either an `Android` (filled) or
     * `AndroidOutlined` enum case — the variant is forwarded to the
     * renderer via the `material_variant` wire prop.
     */
    public function name(
        ?string $name = null,
        IosSymbol|string|null $ios = null,
        AndroidSymbol|string|null $android = null,
    ): static {
        if ($name !== null)    { $this->shared = $name; }
        if ($ios !== null)     { $this->iosOverride = $ios; }
        if ($android !== null) { $this->androidOverride = $android; }

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

        $resolved = IconResolver::resolve($this->shared, $this->iosOverride, $this->androidOverride);
        if ($resolved['icon'] !== null) {
            $props['name'] = $resolved['icon'];
            if ($resolved['variant'] !== null) {
                $props['material_variant'] = $resolved['variant'];
            }
        }

        return $props;
    }
}
