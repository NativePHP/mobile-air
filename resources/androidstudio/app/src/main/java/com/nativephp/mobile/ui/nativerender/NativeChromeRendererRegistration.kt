package com.nativephp.mobile.ui.nativerender

/**
 * Built-in framework-level renderers for the chrome sentinels emitted
 * by `wrapWithChrome` when a layout opts into native chrome via
 * `NativeLayout::usesNativeChrome() = true`. These aren't plugin
 * components — they ship with mobile-air. They register first and under
 * the core owner, so no plugin renderer can take them over. Keep this list
 * in step with `CoreRenderers::ANDROID` in PHP (CoreRenderersTest checks it).
 *
 * Counterpart to `BridgeFunctionRegistration.swift` on iOS.
 */
fun registerNativeChromeRenderers() {
    NativeRendererRegistry.register("native_root_stack", NativeRendererRegistry.CORE_OWNER, NodeRenderer { node, modifier ->
        NativeRootStackRenderer(node, modifier)
    })
    NativeRendererRegistry.register("native_root_tabs", NativeRendererRegistry.CORE_OWNER, NodeRenderer { node, modifier ->
        NativeRootTabsRenderer(node, modifier)
    })
    // Marker elements consumed by the parent renderers — register as
    // no-ops so they don't fall through to the default container.
    NativeRendererRegistry.register("tab_accessory", NativeRendererRegistry.CORE_OWNER, NodeRenderer { _, _ -> })
}
