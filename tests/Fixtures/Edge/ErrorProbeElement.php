<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\FieldErrors;

/** Stands in for a UI-plugin input: shows the bound field's error, unless one is authored. */
class ErrorProbeElement extends TextInput
{
    protected string $type = 'error_probe';

    public function applyAttributes(array $attrs): void
    {
        parent::applyAttributes($attrs);

        $message = ($attrs['error'] ?? '') !== '' ? $attrs['error'] : FieldErrors::for($attrs);

        if ($message !== null) {
            $this->setProp('error', $message);
        }
    }
}
