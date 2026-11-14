<?php

namespace Native\Mobile\Edge\Exceptions;

use InvalidArgumentException;
use Native\Mobile\Edge\NativeComponent;

/**
 * A handler in a view (`@press`, `@dismiss`, `@change`...) that cannot run:
 * it names a method the component does not have, it is an expression the
 * handler syntax does not support, or it sets a property it may not.
 */
class InvalidHandlerException extends InvalidArgumentException
{
    private const SYNTAX = 'A handler is method, method(\'arg\', ...), $property = <literal> or $property = !$property.';

    public static function unknown(NativeComponent $component, string $handler): self
    {
        if (str_starts_with(trim($handler), '$')) {
            return new self("Handler [{$handler}] on [".$component::class.'] is not an expression handlers support. '
                .'A value must be true, false, null, a number or a quoted string. '.self::SYNTAX);
        }

        return new self("Handler [{$handler}] on [".$component::class."] names no method {$handler}(). ".self::SYNTAX);
    }

    public static function property(NativeComponent $component, string $property): self
    {
        return new self('A handler on ['.$component::class."] sets [\${$property}], which is not a public property of it.");
    }

    public static function notBool(NativeComponent $component, string $property, mixed $value): self
    {
        return new self('A handler on ['.$component::class."] toggles [\${$property}], which holds ".get_debug_type($value).', not a bool.');
    }
}
