<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Icon\IosSymbol;

class TopBarAction extends Element
{
    protected string $type = 'top_bar_action';

    protected array $props = [];

    /**
     * Platform icon overrides (`:ios-icon` / `:android-icon`, or the
     * `<icon>`-style `:ios` / `:android`) — enum case or raw string,
     * resolved against the shared `icon` fallback at serialization.
     */
    private IosSymbol|string|null $iosIcon = null;

    private AndroidSymbol|string|null $androidIcon = null;

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['material-variant']) && ! isset($attrs['material_variant'])) {
            $attrs['material_variant'] = $attrs['material-variant'];
        }

        foreach (['id', 'icon', 'material_variant', 'label', 'subtitle', 'url', 'event', 'role'] as $key) {
            if (isset($attrs[$key])) {
                $this->props[$key] = $attrs[$key];
            }
        }

        $this->iosIcon = $attrs['ios-icon'] ?? $attrs['iosIcon'] ?? $attrs['ios'] ?? $this->iosIcon;
        $this->androidIcon = $attrs['android-icon'] ?? $attrs['androidIcon'] ?? $attrs['android'] ?? $this->androidIcon;
        if (isset($attrs['destructive'])) {
            $this->props['destructive'] = (bool) $attrs['destructive'];
            if (! isset($this->props['role']) && $this->props['destructive']) {
                $this->props['role'] = 'destructive';
            }
        }
        if (isset($attrs['divider'])) {
            $this->props['divider'] = (bool) $attrs['divider'];
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $shared = $this->props['icon'] ?? null;
        if ($shared instanceof \BackedEnum) {
            $shared = (string) $shared->value;
        }
        $resolved = IconResolver::resolve($shared, $this->iosIcon, $this->androidIcon);
        if ($resolved['icon'] !== null) {
            $this->props['icon'] = $resolved['icon'];
            if ($resolved['variant'] !== null && ! isset($this->props['material_variant'])) {
                $this->props['material_variant'] = $resolved['variant'];
            }
        }

        if (! empty($this->props['url']) && $this->pressMethod === null) {
            $this->setNavigateConfig([
                'type' => 'navigate',
                'uri' => $this->props['url'],
                'data' => [],
                'transition' => 'none',
            ]);
        }

        return $this->props;
    }
}
