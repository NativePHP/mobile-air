<?php

namespace Native\Mobile\Plugins\Compilers\IOS;

final class ExtensionTargetProjectId
{
    /** @var list<string> */
    private const HOST_ROLES = [
        'device-copy-phase',
        'simulator-copy-phase',
    ];

    /** @var list<string> */
    private const TARGET_ROLES = [
        'device-embed-file',
        'simulator-embed-file',
        'device-proxy',
        'simulator-proxy',
        'product',
        'exceptions',
        'sync-group',
        'frameworks-phase',
        'target',
        'resources-phase',
        'sources-phase',
        'device-dependency',
        'simulator-dependency',
        'debug-configuration',
        'release-configuration',
        'configuration-list',
    ];

    public static function for(string $name, string $role): string
    {
        return strtoupper(substr(hash('sha256', "nativephp|ios-extension|{$name}|{$role}"), 0, 24));
    }

    /** @return list<string> */
    public static function hostIds(): array
    {
        return array_map(fn (string $role): string => self::for('hosts', $role), self::HOST_ROLES);
    }

    /** @return list<string> */
    public static function targetIds(string $name): array
    {
        return array_map(fn (string $role): string => self::for($name, $role), self::TARGET_ROLES);
    }
}
