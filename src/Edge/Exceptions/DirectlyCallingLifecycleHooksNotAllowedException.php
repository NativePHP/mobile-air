<?php

namespace Native\Mobile\Edge\Exceptions;

use BadMethodCallException;
use Native\Mobile\Edge\NativeComponent;

/**
 * Derived from Livewire's
 * `Livewire\Exceptions\DirectlyCallingLifecycleMethodsNotAllowedException`.
 * Copyright (c) Caleb Porzio, MIT licensed. See THIRD-PARTY.md.
 */
class DirectlyCallingLifecycleHooksNotAllowedException extends BadMethodCallException
{
    public function __construct(string $method, NativeComponent $component)
    {
        parent::__construct(
            'Lifecycle hook ['.$method.'] cannot be called directly on component ['.$component::class.'].'
        );
    }
}
