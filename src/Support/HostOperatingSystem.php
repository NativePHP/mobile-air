<?php

declare(strict_types=1);

namespace Native\Mobile\Support;

/**
 * Wraps `PHP_OS_FAMILY` (the machine running this CLI process, not the app's
 * target platform — see `Native\Mobile\Platform` for that) so call sites
 * compare against a fixed enum instead of matching its raw string values.
 */
enum HostOperatingSystem: string
{
    case Darwin = 'Darwin';
    case Windows = 'Windows';
    case Linux = 'Linux';
    case BSD = 'BSD';
    case Solaris = 'Solaris';
    case Unknown = 'Unknown';

    public static function current(): self
    {
        return self::tryFrom(PHP_OS_FAMILY) ?? self::Unknown;
    }
}
