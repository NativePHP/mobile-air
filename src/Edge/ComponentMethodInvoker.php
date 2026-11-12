<?php

namespace Native\Mobile\Edge;

use Illuminate\Container\BoundMethod;
use Illuminate\Contracts\Routing\UrlRoutable as ImplicitlyBindable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Support\Str;
use Native\Mobile\Attributes\NativeAction;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\Exceptions\DirectlyCallingLifecycleHooksNotAllowedException;
use Native\Mobile\Edge\Exceptions\FragmentActionNotAllowedException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The single invocation path for methods called by component interactions.
 *
 * It enforces component action rules: only public userland methods may be
 * called, lifecycle hooks are protected, ordinary classes are injected from
 * Laravel's container, and routable models/backed enums are implicitly bound.
 *
 * Derived from Livewire's `Livewire\ImplicitlyBoundMethod`; the lifecycle-hook
 * guard in ensureCallable() comes from
 * `Livewire\Features\SupportLifecycleHooks\SupportLifecycleHooks::call()`.
 * Copyright (c) Caleb Porzio, MIT licensed. See LICENSE.md.
 */
class ComponentMethodInvoker extends BoundMethod
{
    public static function invoke(
        NativeComponent $component,
        string $method,
        array $parameters = [],
    ): mixed {
        static::ensureCallable($component, $method);

        return static::call(app(), [$component, $method], $parameters);
    }

    /** @internal Framework-owned lifecycle invocation with the same DI rules. */
    public static function invokeLifecycle(
        NativeComponent $component,
        string $method,
        array $parameters = [],
    ): mixed {
        $dependencies = static::resolveMethodDependencies(
            app(),
            [$component, $method],
            $parameters,
        )['positional'];

        return (new ReflectionMethod($component, $method))
            ->invokeArgs($component, $dependencies);
    }

    /**
     * Throw unless the method is one a template or the device may name:
     * public, not static, not a lifecycle hook, not declared on
     * NativeComponent itself, and not render().
     */
    public static function assertCallable(NativeComponent $component, string $method): void
    {
        static::ensureCallable($component, $method);
    }

    /**
     * Throw unless a server-rendered fragment may run this method with
     * these arguments (docs/ASGARD-PROTOCOL.md §10): everything
     * assertCallable() asks, plus a `#[NativeAction]` attribute on the
     * method, and parameters that take the arguments as they are.
     *
     * The arguments are JSON values passed positionally, with no
     * container injection and no model or enum binding, so a parameter
     * typed with a class or interface (or `callable`, or `object`) is
     * refused, as is one taken by reference. Each argument must already
     * be of its parameter's type: nothing is coerced.
     */
    public static function assertFragmentCallable(NativeComponent $component, string $method, array $args): void
    {
        static::ensureCallable($component, $method);

        $reflection = new ReflectionMethod($component, $method);

        if ($reflection->getAttributes(NativeAction::class) === []) {
            throw new FragmentActionNotAllowedException($component, $method, 'it is not marked #[NativeAction]');
        }

        $args = array_values($args);
        $parameters = $reflection->getParameters();

        foreach ($parameters as $parameter) {
            if ($parameter->isPassedByReference()) {
                throw new FragmentActionNotAllowedException($component, $method, "\${$parameter->getName()} is taken by reference");
            }

            if (! static::jsonParameterType($parameter->getType())) {
                throw new FragmentActionNotAllowedException($component, $method, "\${$parameter->getName()} is not typed with JSON types only");
            }
        }

        if (count($args) < $reflection->getNumberOfRequiredParameters()) {
            throw new FragmentActionNotAllowedException($component, $method, 'too few arguments');
        }

        foreach ($args as $i => $arg) {
            $parameter = $parameters[$i] ?? null;

            if ($parameter === null) {
                $last = end($parameters);

                if ($last === false || ! $last->isVariadic()) {
                    break;
                }

                $parameter = $last;
            }

            if (! static::acceptsJsonValue($parameter->getType(), $arg)) {
                throw new FragmentActionNotAllowedException(
                    $component,
                    $method,
                    'argument '.($i + 1)." does not match \${$parameter->getName()}'s type",
                );
            }
        }
    }

    /** Untyped, or built only from types a JSON value can be. */
    protected static function jsonParameterType(?\ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (! static::jsonParameterType($member)) {
                    return false;
                }
            }

            return true;
        }

        return $type instanceof ReflectionNamedType
            && $type->isBuiltin()
            && in_array($type->getName(), ['mixed', 'null', 'bool', 'true', 'false', 'int', 'float', 'string', 'array', 'iterable'], true);
    }

    protected static function acceptsJsonValue(?\ReflectionType $type, mixed $value): bool
    {
        if ($type === null) {
            return true;
        }

        if ($value === null && $type->allowsNull()) {
            return true;
        }

        $members = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];

        foreach ($members as $member) {
            $matches = match ($member->getName()) {
                'mixed' => true,
                'bool' => is_bool($value),
                'true' => $value === true,
                'false' => $value === false,
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'string' => is_string($value),
                'array', 'iterable' => is_array($value),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    protected static function ensureCallable(NativeComponent $component, string $method): void
    {
        $protectedMethods = [
            'mount',
            'boot',
            'booted',
            'exception',
            'hydrate*',
            'dehydrate*',
            'updating*',
            'updated*',
            'rendering',
            'rendered',
            'scriptSrc',
        ];

        foreach (class_uses_recursive($component) as $trait) {
            $traitName = class_basename($trait);
            $protectedMethods[] = 'mount'.$traitName;
            $protectedMethods[] = 'boot'.$traitName;
            $protectedMethods[] = 'booted'.$traitName;
        }

        if (Str::is($protectedMethods, $method)) {
            throw new DirectlyCallingLifecycleHooksNotAllowedException($method, $component);
        }

        if (! method_exists($component, $method)) {
            throw new ComponentMethodNotFoundException($method, $component);
        }

        $reflection = new ReflectionMethod($component, $method);

        if (! $reflection->isPublic()
            || $reflection->isStatic()
            || $method === 'render'
            || $reflection->getDeclaringClass()->getName() === NativeComponent::class) {
            throw new ComponentMethodNotFoundException($method, $component);
        }
    }

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
        $nullable = $parameter->allowsNull();

        if (array_key_exists($name, $parameters) && ! $parameters[$name] instanceof $class) {
            $parameters[$name] = static::getImplicitBinding($container, $class, $parameters[$name], $nullable);
        } elseif (array_key_exists($class, $parameters) && ! $parameters[$class] instanceof $class) {
            $parameters[$class] = static::getImplicitBinding($container, $class, $parameters[$class], $nullable);
        }
    }

    protected static function getClassForDependencyInjection($parameter): ?string
    {
        $class = static::getParameterClassName($parameter);

        if ($class === null || enum_exists($class) || static::implementsImplicitlyBindable($parameter)) {
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

    protected static function getImplicitBinding($container, string $class, mixed $value, bool $nullable = false): mixed
    {
        // A cleared native control (select, text input) sends an empty string
        // rather than null. For a nullable binding that means "no value", not
        // "case not found".
        if ($value === null || ($nullable && $value === '')) {
            return null;
        }

        if (is_a($class, \BackedEnum::class, true)) {
            $resolved = $class::tryFrom($value);

            if ($resolved === null) {
                throw new BackedEnumCaseNotFoundException($class, $value);
            }

            return $resolved;
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

        return $class !== null && is_a($class, \BackedEnum::class, true);
    }
}
