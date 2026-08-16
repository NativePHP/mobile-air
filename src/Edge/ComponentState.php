<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Attributes\Locked;
use Native\Mobile\Edge\Exceptions\LockedPropertyException;
use ReflectionProperty;

/** Central state writer for native:model and the component test harness. */
class ComponentState
{
    public static function set(NativeComponent $component, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $property = array_shift($segments);

        if ($property === '' || ! property_exists($component, $property)) {
            return;
        }

        $reflection = new ReflectionProperty($component, $property);

        if (! $reflection->isPublic() || $reflection->isStatic()) {
            return;
        }

        if ($reflection->getAttributes(Locked::class) !== []) {
            throw new LockedPropertyException($component::class, $property);
        }

        $propertyName = str($property)->studly()->toString();
        $nestedName = $segments === []
            ? null
            : str(str_replace('.', '_', $path))->studly()->toString();
        $keyAfterFirstDot = $segments === [] ? null : implode('.', $segments);
        $keyAfterLastDot = $segments === [] ? null : end($segments);

        $component->__invokeStateHook('updating', [$path, $value]);
        $component->__invokeStateHook('updating'.$propertyName, [$value, $keyAfterFirstDot]);

        if ($nestedName !== null) {
            $component->__invokeStateHook('updating'.$nestedName, [$value, $keyAfterLastDot]);
        }

        if ($segments === []) {
            $component->{$property} = $value;
        } else {
            $target = $component->{$property};
            data_set($target, implode('.', $segments), $value);
            $component->{$property} = $target;
        }

        $component->__forgetComputedAfterStateMutation();

        $component->__invokeStateHook('updated', [$path, $value]);
        $component->__invokeStateHook('updated'.$propertyName, [$value, $keyAfterFirstDot]);

        if ($nestedName !== null) {
            $component->__invokeStateHook('updated'.$nestedName, [$value, $keyAfterLastDot]);
        }
    }
}
