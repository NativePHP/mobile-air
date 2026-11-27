<?php

namespace Native\Mobile\Edge\Contracts;

use Closure;
use Native\Mobile\Edge\Extensions\RunloopExtensionBase;
use Native\Mobile\Edge\NativeComponent;
use SupaNative\Core\Edge\Enums\ActionSource;

/**
 * A package's hook into every screen's runloop. Register one with
 * NativeComponent::extend(), usually from a service provider's boot().
 *
 * Every method is called for every screen, so an extension that has
 * nothing to do should return as quickly as it can. Extend
 * {@see RunloopExtensionBase} to implement
 * only the hooks you need.
 *
 * The `$tree` the loop hooks receive is the screen and its nested child
 * components, screen first, as a list.
 */
interface RunloopExtension
{
    /** Called before a screen renders a frame, for the Element and the streaming path alike. */
    public function beginFrame(): void;

    /** Called after the screen's frame rendered without throwing. */
    public function endFrame(NativeComponent $root): void;

    /**
     * Milliseconds until this extension needs a turn of the loop when no
     * input arrives, 0 to repaint now, or null when it has nothing waiting.
     * The loop sleeps for the shortest of this and its own poll timers. An
     * extension that keeps answering 0 keeps the loop awake.
     *
     * @param  list<NativeComponent>  $tree
     */
    public function nextWake(array $tree): ?int;

    /**
     * Run at the top of each turn of the loop, before the frame. Whatever
     * it throws shows the error screen, as an interaction would. Events
     * it dispatches are delivered straight after. Return true when the
     * screen needs a new frame.
     *
     * @param  list<NativeComponent>  $tree
     */
    public function tick(array $tree): bool;

    /**
     * Offered every native event before the app's listeners. Return true
     * to claim it, and nothing else hears it.
     */
    public function handleNativeEvent(string $name, mixed $payload): bool;

    /**
     * The handler for a UI callback id this extension registered on the
     * component, or null when the id is not one of its own. The closure is
     * called with the value the event carries (the text, the toggle state
     * and so on), or with nothing for a press.
     *
     * An id claimed by more than one component or extension is dropped,
     * so an extension cannot take over a callback the screen owns.
     */
    public function resolveCallback(NativeComponent $component, int $id): ?Closure;

    /**
     * Wrap a developer action. Runs after the method passed the guard its
     * source needs, so a refused call never gets here. Call `$next()` to
     * run the action (and any extension registered after this one), or
     * return without calling it to run something else in its place.
     *
     * `$next` takes no arguments: the method and arguments that passed the
     * guard are the ones that run.
     *
     * @param  Closure(): mixed  $next
     */
    public function aroundAction(NativeComponent $component, string $method, array $args, ActionSource $source, Closure $next): mixed;

    /**
     * Called when a component unmounts, its children first. Drop anything
     * held for it.
     */
    public function componentUnmounted(NativeComponent $component): void;
}
