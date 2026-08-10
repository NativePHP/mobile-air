<?php

it('defers published tab navigation state changes until after the SwiftUI body update', function () {
    $renderer = file_get_contents(
        dirname(__DIR__, 2).'/resources/xcode/NativePHP/NativeRender/NativeRootTabsRenderer.swift'
    );
    $coordinator = file_get_contents(
        dirname(__DIR__, 2).'/resources/xcode/NativePHP/NativeRender/PerTabNavigationCoordinator.swift'
    );

    expect($renderer)
        ->not->toContain('coord.cache(uri: currentUri, node: node)')
        ->toMatch('/DispatchQueue\.main\.async\s*\{\s*coord\.receive\(uri: currentUri, rootNode: node\)\s*\}/');

    expect($coordinator)
        ->toContain('@Published var rootNodeCache')
        ->not->toContain('func cache(uri: String, node: NativeUINode)');
});

it('silently resets a coordinator before cold mounting a new navigation stack', function () {
    $coordinator = file_get_contents(
        dirname(__DIR__, 2).'/resources/xcode/NativePHP/NativeRender/NavigationCoordinator.swift'
    );

    expect($coordinator)
        ->toMatch('/func reset\(\)\s*\{.*?_path = Published\(initialValue: \[\]\).*?\}/s')
        ->not->toMatch('/func reset\(\)\s*\{.*?path = \[\].*?\}/s');
});
