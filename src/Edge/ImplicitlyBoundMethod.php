<?php

namespace Native\Mobile\Edge;

use Illuminate\Container\BoundMethod;
use Illuminate\Contracts\Routing\UrlRoutable as ImplicitlyBindable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Resolves component method parameters with the same rules as Livewire:
 * ordinary classes come from the container; routable models and backed enums
 * are implicitly bound from matching values passed to the method.
 */
class ImplicitlyBoundMethod extends BoundMethod
{
    protected static function getMethodDependencies($container, $callback, array $parameters = [])
    {
        return static::resolveMethodDependencies($container, $callback, $parameters)['positional'];
    }

    public static function resolveMethodDependencies($container, $callback, array $parameters = []): array
    {
        $positional = [];
        $named = [];
        $parameterIndex = 0;

        foreach (static::getCallReflector($callback)->getParameters() as $parameter) {
            $parameterPosition = count($positional);

            static::substituteNameBindingForCallParameter($parameter, $parameters, $parameterIndex);
            static::substituteImplicitBindingForCallParameter($container, $parameter, $parameters);
            static::addDependencyForCallParameter($container, $parameter, $parameters, $positional);

            $parameterDependencies = array_slice($positional, $parameterPosition);

            if ($parameterDependencies !== []) {
                $named[$parameter->getName()] = $parameter->isVariadic()
                    ? $parameterDependencies
                    : $parameterDependencies[0];
            }
        }

        return [
            'positional' => array_values(array_merge($positional, $parameters)),
            'named' => $named,
        ];
    }

    protected static function substituteNameBindingForCallParameter($parameter, array &$parameters, int &$parameterIndex): void
    {
        if (! array_key_exists($parameterIndex, $parameters)) {
            return;
        }

        if ($parameter->isVariadic()) {
            $parameters = array_merge(
                array_filter($parameters, fn ($key) => ! is_int($key), ARRAY_FILTER_USE_KEY),
                array_values(array_filter($parameters, fn ($key) => is_int($key), ARRAY_FILTER_USE_KEY)),
            );

            return;
        }

        $class = static::getClassForDependencyInjection($parameter);

        if ($class !== null && ! $parameters[$parameterIndex] instanceof $class) {
            return;
        }

        if (! array_key_exists($parameter->getName(), $parameters)) {
            $parameters[$parameter->getName()] = $parameters[$parameterIndex];
            unset($parameters[$parameterIndex]);
            $parameterIndex++;
        }
    }

    protected static function substituteImplicitBindingForCallParameter($container, $parameter, array &$parameters): void
    {
        $class = static::getClassForImplicitBinding($parameter);

        if ($class === null) {
            return;
        }

        $name = $parameter->getName();

        if (array_key_exists($name, $parameters) && ! $parameters[$name] instanceof $class) {
            $parameters[$name] = static::getImplicitBinding($container, $class, $parameters[$name]);
        } elseif (array_key_exists($class, $parameters) && ! $parameters[$class] instanceof $class) {
            $parameters[$class] = static::getImplicitBinding($container, $class, $parameters[$class]);
        }
    }

    protected static function getClassForDependencyInjection($parameter): ?string
    {
        $class = static::getParameterClassName($parameter);

        if ($class === null || static::isEnum($parameter) || static::implementsImplicitlyBindable($parameter)) {
            return null;
        }

        return $class;
    }

    protected static function getClassForImplicitBinding($parameter): ?string
    {
        $class = static::getParameterClassName($parameter);

        if ($class === null) {
            return null;
        }

        return static::isEnum($parameter) || static::implementsImplicitlyBindable($parameter)
            ? $class
            : null;
    }

    protected static function getImplicitBinding($container, string $class, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ((new ReflectionClass($class))->isEnum()) {
            return $class::tryFrom($value);
        }

        $model = $container->make($class)->resolveRouteBinding($value);

        if (! $model) {
            throw (new ModelNotFoundException)->setModel($class, [$value]);
        }

        return $model;
    }

    public static function getParameterClassName($parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    public static function implementsImplicitlyBindable($parameter): bool
    {
        $class = static::getParameterClassName($parameter);

        return $class !== null
            && (new ReflectionClass($class))->implementsInterface(ImplicitlyBindable::class);
    }

    public static function isEnum($parameter): bool
    {
        $class = static::getParameterClassName($parameter);

        return $class !== null && (new ReflectionClass($class))->isEnum();
    }
}
