<?php

namespace Native\Mobile\Edge\Contracts;

use Native\Mobile\Edge\Runtime\ComponentPublished;
use Native\Mobile\Edge\Runtime\DispatchFinished;
use Native\Mobile\Edge\Runtime\DispatchStarting;
use Native\Mobile\Edge\Runtime\RuntimeFailed;

/**
 * Opt-in observer for the native component runtime.
 *
 * Core does not register an observer. Developer tools, recorders, and other
 * packages may attach one through RuntimeObservers when they need component
 * context, dispatch timing, or failure information beyond the published tree.
 */
interface RuntimeObserver
{
    /** A component frame was published. */
    public function componentPublished(ComponentPublished $event): void;

    /** A UI or native event is about to be dispatched. */
    public function dispatchStarting(DispatchStarting $event): void;

    /** A UI or native event finished dispatching. */
    public function dispatchFinished(DispatchFinished $event): void;

    /** The component runtime caught a failure and is about to render its error UI. */
    public function failed(RuntimeFailed $event): void;
}
