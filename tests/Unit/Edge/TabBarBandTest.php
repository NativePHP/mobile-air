<?php

/**
 * The blur band under the iOS tab bar is drawn in Swift. Both failures these
 * guard only show on a device, so they read the source.
 */
function tabBarScrollEdgeSource(): string
{
    return file_get_contents(dirname(__DIR__, 3).'/resources/xcode/NativePHP/NativeRender/TabBarScrollEdge.swift');
}

it('draws the band and hides the bottom edge effect only while the tab bar shows', function () {
    expect(tabBarScrollEdgeSource())
        // The bar itself is asked, so a bar hidden by content inside the
        // root level counts too, not only core's own push and hide signals.
        ->toContain('TabBarVisibilityProbe { tabBarShows = $0 }')
        ->toContain('controller.tabBarController')
        ->toContain('bar.observe(\.isHidden)')
        ->toContain('.scrollEdgeEffectHidden(tabBarShows && style != "hard", for: .bottom)')
        ->toContain('if tabBarShows && style == "soft" {')
        ->not->toContain('.scrollEdgeEffectHidden(true, for: .bottom)');
});

it('re-arms the band a turn after light or dark mode changes', function () {
    preg_match(
        '/registerForTraitChanges\(\[UITraitUserInterfaceStyle\.self\]\) \{ \(view: PartialBlurUIView, _\) in(.*?)\n        \}/s',
        tabBarScrollEdgeSource(),
        $handler,
    );

    // Armed inside the trait change, the effect view's new filters miss the
    // paused animation and the band turns into an opaque strip.
    expect($handler[1] ?? null)
        ->toContain('DispatchQueue.main.async { [weak view] in view?.arm() }')
        ->not->toMatch('/^\s*view\.arm\(\)\s*$/m');
});
