<?php

namespace Native\Mobile\Edge;

use BackedEnum;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/** Resolve route models and enums used by mount() or typed public properties. */
class ComponentRouteBinder
{
    public static function resolve(Route $route, string $componentClass): void
    {
        $types = array_replace(
            static::publicPropertyTypes($componentClass),
            static::mountParameterTypes($componentClass),
        );

        // URI order matters for scoped bindings: a child model must resolve
        // after its parent parameter has become an UrlRoutable instance.
        foreach ($route->parametersWithoutNulls() as $name => $value) {
            $class = $types[$name] ?? null;

            if ($class === null || ! static::isBindable($class)) {
                continue;
            }

            $route->setParameter(
                $name,
                static::resolveParameter($route, $name, $class, $value),
            );
        }
    }

    /** @return array<string, class-string> */
    private static function publicPropertyTypes(string $componentClass): array
    {
        $types = [];

        foreach ((new ReflectionClass($componentClass))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getDeclaringClass()->getName() === NativeComponent::class) {
                continue;
            }

            if (($class = static::className($property->getType())) !== null) {
                $types[$property->getName()] = $class;
            }
        }

        return $types;
    }

    /** @return array<string, class-string> */
    private static function mountParameterTypes(string $componentClass): array
    {
        if (! method_exists($componentClass, 'mount')) {
            return [];
        }

        $types = [];

        foreach ((new ReflectionMethod($componentClass, 'mount'))->getParameters() as $parameter) {
            if (($class = static::className($parameter->getType())) !== null) {
                $types[$parameter->getName()] = $class;
            }
        }

        return $types;
    }

    /** @return class-string|null */
    private static function className(?\ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
            ? $type->getName()
            : null;
    }

    private static function isBindable(string $class): bool
    {
        return is_a($class, UrlRoutable::class, true)
            || (enum_exists($class) && is_a($class, BackedEnum::class, true));
    }

    private static function resolveParameter(Route $route, string $name, string $class, mixed $value): mixed
    {
        if ($value instanceof $class) {
            return $value;
        }

        if (enum_exists($class)) {
            $resolved = $class::tryFrom($value);

            if ($resolved === null) {
                throw new BackedEnumCaseNotFoundException($class, $value);
            }

            return $resolved;
        }

        /** @var UrlRoutable $instance */
        $instance = app()->make($class);
        $parent = $route->parentOfParameter($name);
        $shouldScope = $parent instanceof UrlRoutable
            && ! $route->preventsScopedBindings()
            && ($route->enforcesScopedBindings() || array_key_exists($name, $route->bindingFields()));

        if ($shouldScope) {
            $resolved = $parent->resolveChildRouteBinding(
                $name,
                $value,
                $route->bindingFieldFor($name),
            );
        } elseif ($route->allowsTrashedBindings()) {
            $resolved = $instance->resolveSoftDeletableRouteBinding(
                $value,
                $route->bindingFieldFor($name),
            );
        } else {
            $resolved = $instance->resolveRouteBinding(
                $value,
                $route->bindingFieldFor($name),
            );
        }

        if (! $resolved) {
            throw (new ModelNotFoundException)->setModel($class, [$value]);
        }

        return $resolved;
    }
}
