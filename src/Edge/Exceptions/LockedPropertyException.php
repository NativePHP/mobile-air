<?php

namespace Native\Mobile\Edge\Exceptions;

class LockedPropertyException extends \RuntimeException
{
    public function __construct(string $component, string $property)
    {
        parent::__construct(
            "Cannot sync [{$property}] on [{$component}]: the property is #[Locked]. "
            .'Locked properties only change through component methods, never through bindings.'
        );
    }
}
