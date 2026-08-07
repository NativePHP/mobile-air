<?php

namespace Native\Mobile\Edge\Exceptions;

use Exception;

/**
 * Thrown when a child-component tag is given slot content:
 *
 *     <native:user-card :user="$user">
 *         <native:text>not allowed</native:text>   ← throws
 *     </native:user-card>
 *
 * v1 of nested components deliberately rejects children instead of
 * half-implementing slots — a child renders exactly what its own view
 * declares. Slots are a documented follow-up; when they land, this
 * exception goes away.
 */
class ComponentSlotNotSupportedException extends Exception
{
    public function __construct(string $componentTag, string $childType)
    {
        parent::__construct(
            "Child components do not support slot content yet: <native:{$componentTag}> received a nested <native:{$childType}> element. "
            ."Pass data via attributes (props) instead, and render the markup inside [{$componentTag}]'s own view."
        );
    }
}
