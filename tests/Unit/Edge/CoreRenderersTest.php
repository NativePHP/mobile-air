<?php

use Native\Mobile\Edge\CoreRenderers;
use Native\Mobile\Edge\ElementRegistry;

/**
 * CoreRenderers is the PHP list of types core renders natively. These tests
 * read the Swift and Kotlin sources and fail when core's own registrations
 * drift from it, or register without the core owner.
 */
function coreNativeSources(string $directory, string $extension): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    $sources = [];

    foreach ($files as $file) {
        if ($file->getExtension() === $extension) {
            $sources[$file->getPathname()] = file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

/**
 * @return array<string, string|null> type => owner expression (null when the call names none)
 */
function coreRendererRegistrations(array $sources, string $pattern): array
{
    $registrations = [];

    foreach ($sources as $source) {
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $registrations[$match[1]] = ($match[2] ?? '') !== '' ? $match[2] : null;
        }
    }

    ksort($registrations);

    return $registrations;
}

function sortedTypes(array $types): array
{
    sort($types);

    return $types;
}

beforeEach(function () {
    $this->root = dirname(__DIR__, 3).'/resources';
});

it('matches what iOS core registers', function () {
    // The generated plugin registration lives in Bridge/Plugins; it is not core.
    $sources = array_filter(
        coreNativeSources($this->root.'/xcode/NativePHP', 'swift'),
        fn (string $path) => ! str_contains($path, '/Bridge/Plugins/'),
        ARRAY_FILTER_USE_KEY,
    );

    $registrations = coreRendererRegistrations(
        $sources,
        '/SwiftUIRendererRegistry\.shared\.register\(\s*"([^"]+)"(?:\s*,\s*owner:\s*([\w.]+|"[^"]*"))?/'
    );

    expect(array_keys($registrations))->toBe(sortedTypes(CoreRenderers::IOS))
        ->and(array_unique(array_values($registrations)))->toBe(['SwiftUIRendererRegistry.coreOwner']);
});

it('matches what Android core registers', function () {
    $registrations = coreRendererRegistrations(
        coreNativeSources($this->root.'/androidstudio/app/src/main', 'kt'),
        '/NativeRendererRegistry\.register\(\s*"([^"]+)"(?:\s*,\s*(NativeRendererRegistry\.\w+|"[^"]*"))?/'
    );

    expect(array_keys($registrations))->toBe(sortedTypes(CoreRenderers::ANDROID))
        ->and(array_unique(array_values($registrations)))->toBe(['NativeRendererRegistry.CORE_OWNER']);
});

it('uses the same core owner name on every platform', function () {
    $swift = file_get_contents($this->root.'/xcode/NativePHP/NativeRender/RenderNode.swift');
    $kotlin = file_get_contents($this->root.'/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeRendererRegistry.kt');

    expect(CoreRenderers::OWNER)->toBe(ElementRegistry::CORE)
        ->and($swift)->toContain('static let coreOwner = "'.CoreRenderers::OWNER.'"')
        ->and($kotlin)->toContain('const val CORE_OWNER = "'.CoreRenderers::OWNER.'"');
});

it('lists every core-rendered type once', function () {
    expect(CoreRenderers::types())->toBe(['native_root_stack', 'native_root_tabs', 'bottom_bar', 'tab_accessory'])
        ->and(CoreRenderers::owns('native_root_tabs'))->toBeTrue()
        ->and(CoreRenderers::owns('text'))->toBeFalse();
});
