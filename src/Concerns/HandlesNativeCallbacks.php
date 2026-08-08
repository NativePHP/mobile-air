<?php

namespace Native\Mobile\Concerns;

use BadMethodCallException;
use Closure;
use InvalidArgumentException;
use Native\Mobile\Support\NativeCallbacks;

/**
 * Adds event-named callback methods to a Pending* builder.
 *
 * Each outcome event gets a fluent handler named after the event class —
 * `lcfirst(class_basename($eventClass))`:
 *
 *     Camera::getPhoto()
 *         ->photoTaken(fn ($event) => ...)
 *         ->photoCancelled(fn () => ...)
 *         ->permissionDenied(fn () => ...);
 *
 * The named methods are resolved through __call against namedEvents() (success
 * event + failureEvents() by default). For a custom event class — e.g. one set
 * via ->event(Custom::class) — use the generic escape hatch:
 *
 *     ->on(Custom::class, fn ($event) => ...)
 *
 * Every path funnels into NativeCallbacks::register(); the callback is correlated
 * by the builder's id and fired (with the event object, rebound to the live
 * component for non-static closures) in Native\Mobile\Edge\NativeComponent.
 *
 * Requires the using class to expose getId(): string and a $eventClass property.
 */
trait HandlesNativeCallbacks
{
    /**
     * Register a callback for a specific event class. Public escape hatch for
     * custom events; also the primitive every named method funnels through.
     */
    public function on(string $eventClass, Closure|array|string $callback): static
    {
        NativeCallbacks::register($this->getId(), $eventClass, $callback);

        return $this;
    }

    /**
     * Register a callback for the operation's SUCCESS event without naming
     * it — `->onSuccess(fn ($event) => …)` reads the same on every builder
     * (photoTaken, mediaSelected, videoRecorded, …). Honors a custom
     * ->event(Custom::class) override, since that replaces $eventClass.
     */
    public function onSuccess(Closure|array|string $callback): static
    {
        return $this->on($this->eventClass, $callback);
    }

    /**
     * Event classes that get a named handler method. Defaults to the success
     * event plus any failure events; override to expose more.
     *
     * @return array<int, class-string>
     */
    protected function namedEvents(): array
    {
        return array_merge([$this->eventClass], $this->failureEvents());
    }

    /**
     * Distinct failure event classes for this operation. Empty by default.
     *
     * @return array<int, class-string>
     */
    protected function failureEvents(): array
    {
        return [];
    }

    /**
     * Dispatch event-named handler calls (photoTaken(), mediaSelected(), ...) to
     * on() by matching the method name against namedEvents().
     */
    public function __call(string $name, array $arguments): static
    {
        foreach ($this->namedEvents() as $eventClass) {
            if ($eventClass === null || lcfirst(class_basename($eventClass)) !== $name) {
                continue;
            }

            $callback = $arguments[0] ?? null;

            if (! $callback instanceof Closure && ! is_array($callback) && ! is_string($callback)) {
                throw new InvalidArgumentException(
                    sprintf('%s::%s() expects a Closure, callable array, or class-string callback.', static::class, $name)
                );
            }

            return $this->on($eventClass, $callback);
        }

        throw new BadMethodCallException(
            sprintf('Method %s::%s() does not exist.', static::class, $name)
        );
    }
}
