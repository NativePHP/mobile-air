<?php

namespace Native\Mobile\Edge\Exceptions;

use BadMethodCallException;
use Native\Mobile\Edge\NativeComponent;

class ComponentMethodNotFoundException extends BadMethodCallException
{
    public function __construct(string $method, NativeComponent $component)
    {
        parent::__construct(
            'Public method ['.$method.'] not found on component ['.$component::class.'].'
        );
    }
}
