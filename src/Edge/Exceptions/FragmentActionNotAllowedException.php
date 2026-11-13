<?php

namespace Native\Mobile\Edge\Exceptions;

use BadMethodCallException;
use Native\Mobile\Edge\NativeComponent;

/**
 * A server-rendered fragment named a client action it may not run
 * (docs/ASGARD-PROTOCOL.md §10).
 */
class FragmentActionNotAllowedException extends BadMethodCallException
{
    public function __construct(NativeComponent $component, string $method, string $reason)
    {
        parent::__construct(
            'A fragment may not call ['.$method.'] on component ['.$component::class.']: '.$reason.'.'
        );
    }
}
