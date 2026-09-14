<?php

namespace Native\Mobile\Edge\Runtime;

use Native\Mobile\Edge\NativeComponent;
use WeakMap;

/** Assigns stable, process-local identities to native component instances. */
final class ComponentIds
{
    /** @var WeakMap<NativeComponent, string>|null */
    private static ?WeakMap $ids = null;

    private static int $sequence = 0;

    public static function id(NativeComponent $component): string
    {
        self::$ids ??= new WeakMap;

        return self::$ids[$component] ??= 'component-'.(++self::$sequence);
    }
}
