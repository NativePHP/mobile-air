import SwiftUI

/// Shared native UI state for opt-in RTL support.
///
/// Holds the developer's opt-in `rtlSupport` flag (pushed from PHP via
/// `_meta.rtl_support` in the `UI.SetRtlSupport` bridge call) and the
/// derived effective `isRTL`, which is:
///
///     isRTL = rtlSupport && deviceLanguageIsRTL
///
/// This mirrors Android's `NativeUIState` and the PHP-side `Support\Rtl`
/// language list. The *device* locale is authoritative here — the native
/// shell always follows the OS, even when the Laravel locale is `en`.
///
/// All mutations happen on the main queue (see `UIFunctions.SetRtlSupport`),
/// so `@Published` updates drive SwiftUI re-renders safely.
final class NativeUIState: ObservableObject {
    static let shared = NativeUIState()

    /// RTL languages by base ISO 639-1 code. Kept in lockstep with the
    /// PHP `Native\Mobile\Support\Rtl::LANGUAGES` list.
    static let rtlLanguages: Set<String> = [
        "ar", // Arabic
        "he", // Hebrew
        "fa", // Persian / Farsi
        "ur", // Urdu
        "dv", // Divehi / Maldivian
        "ku", // Kurdish
        "ps", // Pashto
        "sd", // Sindhi
        "ug", // Uyghur
        "yi", // Yiddish
    ]

    /// Developer opt-in from `nativephp.rtl_support`. Enabling this does
    /// NOT force RTL — it only permits RTL when the device is RTL.
    @Published var rtlSupport: Bool = false {
        didSet { recompute() }
    }

    /// Effective layout direction for the native shell.
    @Published private(set) var isRTL: Bool = false

    private init() {
        recompute()
    }

    /// Update the opt-in flag from the `UI.SetRtlSupport` bridge call.
    func setRtlSupport(_ enabled: Bool) {
        rtlSupport = enabled
    }

    /// SwiftUI layout direction for the effective RTL state.
    var layoutDirection: LayoutDirection {
        isRTL ? .rightToLeft : .leftToRight
    }

    /// UIKit semantic content attribute for the effective RTL state.
    var semanticContentAttribute: UISemanticContentAttribute {
        isRTL ? .forceRightToLeft : .forceLeftToRight
    }

    private func recompute() {
        isRTL = rtlSupport && Self.deviceLanguageIsRTL()
    }

    // MARK: - Device language

    /// Whether the device's active language is an RTL language.
    static func deviceLanguageIsRTL() -> Bool {
        guard let preferred = Locale.preferredLanguages.first else { return false }
        return isRtlLanguage(preferred)
    }

    /// Whether a locale (or BCP 47 tag) is an RTL language, resolving
    /// through its base language. Pure and deterministic — testable.
    static func isRtlLanguage(_ locale: String) -> Bool {
        return rtlLanguages.contains(baseLanguage(of: locale))
    }

    /// Extract the base language from a BCP 47 tag (e.g. `ar-SA` -> `ar`).
    static func baseLanguage(of locale: String) -> String {
        let first = locale.split(whereSeparator: { $0 == "-" || $0 == "_" }).first
        return (first.map { String($0) } ?? locale).lowercased()
    }
}
