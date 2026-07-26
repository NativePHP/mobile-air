<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;
use Native\Mobile\PendingAlert;

/**
 * @method static PendingAlert alert(string $title, string $message, array $buttons = [])
 * @method static void share(string $title, string $text, string $url)
 * @method static void toast(string $message, string $duration = 'long')
 */
class Dialog extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\Dialog::class;
    }
}
