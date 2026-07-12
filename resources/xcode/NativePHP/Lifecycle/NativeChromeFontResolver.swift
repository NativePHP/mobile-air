import Foundation

/// Seam that lets a UI plugin resolve chrome font tokens without core
/// depending on the plugin. A `font_name` prop on the chrome sentinels
/// (`native_root_stack` / `native_root_tabs`) names a bundled font file
/// (basename, e.g. "Inter-Bold"); how that maps to a loadable font (asset
/// lookup, CoreText registration, PostScript naming) is plugin knowledge.
///
/// The plugin sets [resolvePostScriptName] from its init function (e.g.
/// native-ui's `registerNativeUIChrome()`). When nothing is registered — no
/// UI plugin installed — chrome falls back to the system font, so core
/// builds and runs standalone.
enum NativeChromeFontResolver {

    /// Token → PostScript name usable with `UIFont(name:)` / `Font.custom`.
    /// Nil (or a nil return) means "can't resolve" — use the system font.
    static var resolvePostScriptName: ((String) -> String?)?

    /// Resolve a chrome font token, or nil.
    static func postScriptName(for token: String) -> String? {
        guard !token.isEmpty else { return nil }

        return resolvePostScriptName?(token)
    }
}
