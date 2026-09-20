<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null get(string $key)
 * @method static \Native\Mobile\SecureStorageResult read(string $key)
 * @method static bool set(string $key, ?string $value, ?\Native\Mobile\SecureStorageAccessibility $accessibility = null)
 * @method static bool delete(string $key)
 */
class SecureStorage extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Native\Mobile\SecureStorage::class;
    }
}
