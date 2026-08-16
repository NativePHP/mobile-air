<?php

namespace Native\Mobile\Edge\Exceptions;

use BadMethodCallException;
use Native\Mobile\Edge\NativeComponent;

class DirectlyCallingLifecycleHooksNotAllowedException extends BadMethodCallException
{
    public function __construct(string $method, NativeComponent $component)
    {
        parent::__construct(
            'Lifecycle hook ['.$method.'] cannot be called directly on component ['.$component::class.'].'
        );
    }
}
