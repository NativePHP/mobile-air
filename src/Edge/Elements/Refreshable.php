<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Wraps content in a scrolling container with native pull-to-refresh.
 *
 *     <native:refreshable @refresh="loadMore">
 *
 *         @foreach ($items as $item)
 *             <native:row>...</native:row>
 *
 *         @endforeach
 *     </native:refreshable>
 *
 * On iOS the renderer uses SwiftUI `ScrollView { ... }.refreshable { }`.
 * On Android it uses Compose `PullToRefreshBox` + `LazyColumn`. Both
 * platforms show their native pull-to-refresh spinner (and haptics on
 * iOS) without any custom gesture handling.
 *
 * Children are the scrollable content — don't nest another scroll-view
 * inside or you'll get nested scrolling.
 */
class Refreshable extends Element
{
    protected string $type = 'refreshable';

    private ?string $refreshMethod = null;

    private ?bool $showsIndicators = null;

    public static function make(): static
    {
        return new static;
    }

    public function onRefresh(string $method): static
    {
        $this->refreshMethod = $method;

        return $this;
    }

    /**
     * Show or hide the scroll indicators, matching scroll-view's prop of
     * the same name. iOS-only in effect: Compose's LazyColumn draws no
     * indicators to begin with, so Android accepts and ignores it.
     */
    public function showsIndicators(bool $value = true): static
    {
        $this->showsIndicators = $value;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = [];
        if ($this->refreshMethod !== null) {
            $props['on_refresh'] = $registry->register($this->refreshMethod);
        }
        if ($this->showsIndicators !== null) {
            $props['shows_indicators'] = $this->showsIndicators;
        }

        return $props;
    }
}
