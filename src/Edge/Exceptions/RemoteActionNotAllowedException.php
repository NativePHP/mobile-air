<?php

namespace Native\Mobile\Edge\Exceptions;

use BadMethodCallException;
use Native\Mobile\Edge\NativeComponent;

/**
 * Something outside the app named an action it may not run, or passed
 * arguments the action cannot take as they are.
 */
class RemoteActionNotAllowedException extends BadMethodCallException
{
    public function __construct(NativeComponent $component, string $method, string $reason)
    {
        parent::__construct(
            'A remote call may not run ['.$method.'] on component ['.$component::class.']: '.$reason.'.'
        );
    }
}
