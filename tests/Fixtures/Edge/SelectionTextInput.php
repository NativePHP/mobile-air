<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\TextInput;

/**
 * Stub of a selection-aware text input — the exact wiring the mobile-ui
 * plugin's inputs use for `@selectionChange`: the handler registers with
 * the `text_selection` callback kind and its id rides the wire as the
 * `on_selection_change` prop. Dispatch then decodes the packed
 * "{start},{end}\x1F{text}" TEXT_CHANGE payload and invokes the handler
 * as (...boundArgs, string $text, int $start, int $end).
 */
class SelectionTextInput extends TextInput
{
    protected ?string $selectionMethod = null;

    public function onSelectionChange(string $method): static
    {
        $this->selectionMethod = $method;

        return $this;
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = parent::resolveProps($registry);

        if ($this->selectionMethod !== null) {
            $props['on_selection_change'] = $registry->register($this->selectionMethod, 'text_selection');
        }

        return $props;
    }
}
