<?php

namespace Native\Mobile;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Geolocation\LocationUpdated;
use ReflectionFunction;

/**
 * Builder for a continuous location stream (Geolocation::watchPosition()).
 *
 * Unlike the one-shot Pending* builders, the result is not a single event but
 * a stream of LocationUpdated events — so the handler is a PERSISTENT listener
 * on the live component (the same primitive as fluent websocket listeners),
 * not a one-shot NativeCallbacks entry:
 *
 *     $this->watchId = Geolocation::watchPosition(fineAccuracy: true)
 *         ->interval(2000)      // ms between updates (Android pacing)
 *         ->minDistance(5)      // meters moved before a new fix (both platforms)
 *         ->locationUpdated(function ($event) {
 *             $this->lat = $event->latitude;   // $this is the component
 *             $this->lng = $event->longitude;
 *         })
 *         ->getId();
 *
 * The watch stops automatically when the component unmounts; stop it earlier
 * with Geolocation::clearWatch($this->watchId). Multiple concurrent watches
 * are supported — each handler only receives its own watch's updates.
 *
 * Streaming is foreground-only: the OS suspends location delivery when the
 * app is backgrounded (background location needs entitlements this plugin
 * doesn't request).
 */
class PendingLocationWatch
{
    protected ?string $id = null;

    protected string $eventClass = LocationUpdated::class;

    protected bool $fineAccuracy = false;

    protected int $intervalMs = 5000;

    protected float $minDistanceMeters = 0;

    protected bool $started = false;

    protected bool $cleanupRegistered = false;

    /**
     * The component this watch was created from (found by walking the call
     * stack — watchPosition() is called inside mount()). Lets start() register
     * unmount cleanup even for attribute-only usage
     * (#[OnNative(LocationUpdated::class)] with no ->locationUpdated() call),
     * which would otherwise leak a GPS stream past the screen's lifetime.
     */
    protected ?NativeComponent $component = null;

    public function __construct()
    {
        $this->component = $this->detectComponent();
    }

    /**
     * Set a unique identifier for this watch (auto-generated otherwise).
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * The watch's unique identifier — pass to Geolocation::clearWatch() to stop
     * streaming, and compare against $event->id in #[On] listeners.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Dispatch a custom event class instead of LocationUpdated. Its constructor
     * should accept the same named fields (success, latitude, longitude,
     * accuracy, speed, heading, timestamp, provider, error, id).
     */
    public function event(string $eventClass): self
    {
        if (! class_exists($eventClass)) {
            throw new InvalidArgumentException("Event class {$eventClass} does not exist");
        }

        $this->eventClass = $eventClass;

        return $this;
    }

    /**
     * Request high accuracy GPS fixes (vs battery-friendlier network location).
     */
    public function fineAccuracy(bool $fine = true): self
    {
        $this->fineAccuracy = $fine;

        return $this;
    }

    /**
     * Target milliseconds between updates. Paces Android's fused provider;
     * iOS is event-driven (use minDistance() to throttle there).
     */
    public function interval(int $milliseconds): self
    {
        $this->intervalMs = max(0, $milliseconds);

        return $this;
    }

    /**
     * Minimum meters the device must move before another update is delivered.
     */
    public function minDistance(float $meters): self
    {
        $this->minDistanceMeters = max(0, $meters);

        return $this;
    }

    /**
     * Run $callback for EVERY update of this watch. The callback is written
     * inside a component method (bound to $this), so the component is recovered
     * from it and the listener registered there — persistent across renders,
     * cleared (and the watch stopped natively) when the component unmounts.
     */
    public function locationUpdated(Closure $callback): self
    {
        $bound = (new ReflectionFunction($callback))->getClosureThis();
        $component = $bound instanceof NativeComponent ? $bound : $this->component;

        if ($component === null) {
            throw new LogicException(
                'locationUpdated() must be called from within a NativeComponent method '
                .'(e.g. mount()) so updates can be routed back to the component.'
            );
        }

        $watchId = $this->getId();

        // All watches stream through the same event class; deliver only this
        // watch's updates to this handler. Both closures MUST be static: a
        // non-static closure created here implicitly binds $this (this
        // builder), and the component holding it would keep the builder alive
        // — so the __destruct that auto-starts the watch would never fire.
        $component->registerNativeEventListener(
            $this->eventClass,
            static function ($event) use ($callback, $watchId) {
                if (($event->id ?? null) === $watchId) {
                    $callback($event);
                }
            }
        );

        // Leaving the screen stops the stream.
        $this->registerCleanup($component);

        return $this;
    }

    /**
     * Stop the watch when the component unmounts. Once per watch, and the
     * closure must be static — a bound one would capture this builder and
     * keep the auto-starting __destruct from ever firing.
     */
    private function registerCleanup(NativeComponent $component): void
    {
        if ($this->cleanupRegistered) {
            return;
        }

        $this->cleanupRegistered = true;
        $watchId = $this->getId();
        $component->registerCleanup(static fn () => app(Geolocation::class)->clearWatch($watchId));
    }

    /**
     * Find the NativeComponent this builder is being created from, by walking
     * up the call stack (typically to mount()).
     */
    private function detectComponent(): ?NativeComponent
    {
        if (! class_exists(NativeComponent::class)) {
            return null;
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            if (($frame['object'] ?? null) instanceof NativeComponent) {
                return $frame['object'];
            }
        }

        return null;
    }

    /**
     * Start streaming. Auto-called via __destruct for fluent one-liners.
     */
    public function start(): bool
    {
        if ($this->started) {
            return false;
        }

        $this->started = true;

        // Attribute-only usage never calls locationUpdated(), so register the
        // unmount cleanup here with the component captured at construction.
        if ($this->component !== null) {
            $this->registerCleanup($this->component);
        }

        if (function_exists('nativephp_call')) {
            nativephp_call('Geolocation.WatchPosition', json_encode([
                'id' => $this->getId(),
                'event' => $this->eventClass,
                'fineAccuracy' => $this->fineAccuracy,
                'interval' => $this->intervalMs,
                'minDistance' => $this->minDistanceMeters,
            ]));

            return true;
        }

        return false;
    }

    /**
     * Stop this watch's native stream.
     */
    public function stop(): void
    {
        app(Geolocation::class)->clearWatch($this->getId());
    }

    public function __destruct()
    {
        if (! $this->started) {
            $this->start();
        }
    }
}
