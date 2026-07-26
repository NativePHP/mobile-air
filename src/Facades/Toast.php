<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;
use Native\Mobile\PendingToast;

/**
 * @method static PendingToast message(string $message)
 * @method static PendingToast view(\Illuminate\Contracts\View\View|\Illuminate\Contracts\Support\Htmlable|string $view, array $data = [])
 * @method static PendingToast html(string $html)
 * @method static void dismiss(string $id)
 * @method static void dismissAll()
 *
 * @see \Native\Mobile\Toast
 */
class Toast extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\Toast::class;
    }
}
