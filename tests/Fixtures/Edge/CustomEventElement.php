<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Plugin-style leaf used to assert custom `@link` / `@scan` wiring.
 */
class CustomEventElement extends Element
{
    protected string $type = 'custom_widget';

    public ?string $linkMethod = null;

    public ?string $scanMethod = null;

    public static function elementEvents(): array
    {
        return ['link', 'scan'];
    }

    public function onLink(string $method): static
    {
        $this->linkMethod = $method;

        return $this;
    }

    public function onScan(string $method): static
    {
        $this->scanMethod = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = [];

        if ($this->linkMethod !== null) {
            $props['on_link'] = $registry->register($this->linkMethod);
        }

        if ($this->scanMethod !== null) {
            $props['on_scan'] = $registry->register($this->scanMethod);
        }

        return $props;
    }
}
