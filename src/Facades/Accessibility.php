<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void focus(string $ref)
 * @method static void announce(string $message, bool $assertive = false)
 */
class Accessibility extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\Accessibility::class;
    }
}
