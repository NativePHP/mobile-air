<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Contracts\TreeObserver;

/**
 * Static registry fanning the published-tree stream out to observers.
 *
 * The render loop taps call these unconditionally; with no observers
 * registered every call is a no-op loop over an empty array, so the
 * hot path pays nothing. Taps that must compute an argument (e.g.
 * resolving a callback id to a method name) guard on any() first.
 */
class TreeObservers
{
    /** @var TreeObserver[] */
    protected static array $observers = [];

    public static function register(TreeObserver $observer): void
    {
        static::$observers[] = $observer;
    }

    public static function any(): bool
    {
        return static::$observers !== [];
    }

    public static function reset(): void
    {
        static::$observers = [];
    }

    public static function tree(array $tree, string $uri): void
    {
        foreach (static::$observers as $o) {
            $o->tree($tree, $uri);
        }
    }

    public static function event(array $event, ?string $label): void
    {
        foreach (static::$observers as $o) {
            $o->event($event, $label);
        }
    }

    public static function nav(array $payload): void
    {
        foreach (static::$observers as $o) {
            $o->nav($payload);
        }
    }
}
