<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

class Webview extends Element
{
    protected string $type = 'webview';

    protected array $webviewProps = [];

    protected ?string $navigatedMethod = null;

    public static function make(string $src = ''): static
    {
        $el = new static;
        if ($src !== '') {
            $el->webviewProps['src'] = $src;
        }

        return $el;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['src'])) {
            $this->webviewProps['src'] = (string) $attrs['src'];
        }

        if (isset($attrs['html'])) {
            $this->webviewProps['html'] = (string) $attrs['html'];
        }

        // Opt-in toggles. Default posture is locked down — JS off, no DOM
        // storage, no file access, no new windows. Hosts that need richer
        // behavior have to ask for it explicitly.
        if (isset($attrs['javascript']) || isset($attrs['js'])) {
            $this->webviewProps['javascript'] = filter_var(
                $attrs['javascript'] ?? $attrs['js'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (isset($attrs['dom-storage']) || isset($attrs['domStorage'])) {
            $this->webviewProps['dom_storage'] = filter_var(
                $attrs['dom-storage'] ?? $attrs['domStorage'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (isset($attrs['fullscreen'])) {
            $this->fullscreen(filter_var($attrs['fullscreen'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($attrs['php'])) {
            $this->php(filter_var($attrs['php'], FILTER_VALIDATE_BOOLEAN));
        }

        // `_navigated` isn't in the collector's generic callback allowlist
        // (that list only covers core events like `_press` / `_change`), so
        // the `@navigated` Blade sugar lands here for us to wire ourselves.
        if (isset($attrs['_navigated'])) {
            $this->onNavigated((string) $attrs['_navigated']);
        }

        $this->applyA11yAttributes($attrs);
    }

    /**
     * Fill the screen, v3-default-webview style: the element takes all
     * available space and the renderers extend behind the safe areas.
     */
    public function fullscreen(bool $value = true): static
    {
        $this->webviewProps['fullscreen'] = $value;

        if ($value) {
            $this->fill();
        }

        return $this;
    }

    /**
     * Enriched mode: instead of a sandboxed foreign-content view, embed the
     * app's own Laravel webview — served by the built-in PHP runtime with
     * the full `window.Native` bridge, shared session, and asset pipeline.
     * `src` becomes an app route path (`/dashboard`); when omitted, the
     * app's configured start URL loads. The sandbox opt-ins (`javascript`,
     * `dom-storage`) don't apply — the enriched webview needs both and the
     * renderers force them on.
     */
    public function php(bool $value = true): static
    {
        $this->webviewProps['php'] = $value;

        return $this;
    }

    /**
     * `@navigated="onUrlChange"` — fires once per top-frame URL load
     * (committed navigation), with the resolved URL as the first arg.
     */
    public function onNavigated(string $method): static
    {
        $this->navigatedMethod = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = $this->webviewProps;

        if ($this->navigatedMethod !== null) {
            $props['on_navigated'] = $registry->register($this->navigatedMethod);
        }

        return $props;
    }
}
