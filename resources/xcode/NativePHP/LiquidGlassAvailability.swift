//
//  LiquidGlassAvailability.swift
//  NativePHP
//
//  Why the renderer's iOS 26 surfaces are wrapped in `#if compiler(>=6.2)`.
//
//  A `#available(iOS 26.0, *)` check is a RUNTIME gate: it keeps the app from
//  calling a symbol on a device too old to have it. It does nothing at compile
//  time — the symbol still has to exist in the SDK being built against, or the
//  file doesn't type-check at all. Building the renderer with Xcode 16 (iOS 18
//  SDK) therefore failed outright on the iOS 26 API it uses:
//
//      value of type 'TabBarAccessoryModifier.Content'
//      has no member 'tabViewBottomAccessory'
//
//  So each iOS 26 call site needs a second, COMPILE-time gate around the
//  runtime one. Swift has no direct "which SDK am I building against?"
//  condition, but the toolchain and the SDK ship together, so the compiler
//  version stands in for it:
//
//      Xcode 16.0–16.4  →  Swift 6.0–6.1.2  →  iOS 18 SDK
//      Xcode 26.x       →  Swift 6.2+       →  iOS 26 SDK
//
//  `#if compiler(>=6.2)` therefore reads as "the iOS 26 SDK is present". The
//  one way to fool it is a hand-installed swift.org 6.2 toolchain driving an
//  Xcode 16 SDK — not a configuration iOS app builds use.
//
//  The shape at every call site is:
//
//      #if compiler(>=6.2)
//      if #available(iOS 26.0, *) {
//          content.someIOS26Modifier()      // compiled only on Xcode 26+,
//      } else {                             // run only on iOS 26+ devices
//          fallback(content)
//      }
//      #else
//      fallback(content)                    // Xcode 16: iOS 26 path is gone
//      #endif
//
//  with the pre-26 branch factored into a `fallback` helper so the two
//  `#if` arms can't drift apart. Call sites:
//
//    • ContentView.GlassPillBackground              — .glassEffect(in:)
//    • NodeStyleModifier.GlassModifier              — .glassEffect(_:in:)
//    • NodeStyleModifier.WithGlassContainer         — GlassEffectContainer
//    • NativeRootStackRenderer                      — .safeAreaBar(edge:)
//    • NativeRootStackRenderer                      — .navigationSubtitle(_:)
//    • NativeRootTabsRenderer                       — .tabViewBottomAccessory
//
//  Neither gate changes the deployment target: the app targets iOS 18.2 and
//  runs there either way. A binary built with Xcode 16 simply never shows the
//  Liquid Glass treatments, even on an iOS 26 device — it renders the same
//  material/inset fallbacks an iOS 18 device gets. Ship builds should use
//  Xcode 26 — App Store Connect has rejected uploads built against anything
//  older than the iOS 26 SDK since 28 April 2026.
//

#if !compiler(>=6.2)
#warning("""
Building with a pre-Xcode-26 toolchain: the iOS 26 Liquid Glass surfaces \
(glass effects, .safeAreaBar, .navigationSubtitle, tab bottom accessory) are \
compiled out and fall back to their pre-26 equivalents on every device. Build \
with Xcode 26 or later for the full native rendering — and for App Store \
submission, which requires the iOS 26 SDK.
""")
#endif
