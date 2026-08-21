import Testing
@testable import NativePHP

struct NativePHPTests {

    @Test func example() async throws {
        // Write your test here and use APIs like `#expect(...)` to check expected conditions.
    }

    // MARK: - RTL language detection (NativeUIState)

    @Test func baseLanguageExtractsTheIsoCodeFromAVariant() {
        #expect(NativeUIState.baseLanguage(of: "ar-SA") == "ar")
        #expect(NativeUIState.baseLanguage(of: "ar-AE") == "ar")
        #expect(NativeUIState.baseLanguage(of: "he-IL") == "he")
        #expect(NativeUIState.baseLanguage(of: "fa-IR") == "fa")
        #expect(NativeUIState.baseLanguage(of: "ur-PK") == "ur")
        #expect(NativeUIState.baseLanguage(of: "en-US") == "en")
        #expect(NativeUIState.baseLanguage(of: "fr-FR") == "fr")
        #expect(NativeUIState.baseLanguage(of: "AR-sa") == "ar")
    }

    @Test func rtlLocalesAreDetectedThroughTheirBaseLanguage() {
        for locale in ["ar", "ar-SA", "ar-AE", "he", "he-IL", "fa", "fa-IR", "ur", "ur-PK"] {
            #expect(NativeUIState.isRtlLanguage(locale))
        }
    }

    @Test func ltrLocalesAreNotRtl() {
        for locale in ["en", "en-US", "fr-FR", "en-SA", "de", "es"] {
            #expect(!NativeUIState.isRtlLanguage(locale))
        }
    }

    @Test func effectiveRtlRequiresBothOptInAndAnRtlLocale() {
        // Without opt-in, an RTL device still evaluates to LTR.
        NativeUIState.shared.setRtlSupport(false)
        #expect(!NativeUIState.shared.isRTL)

        // With opt-in, the direction follows the device language.
        NativeUIState.shared.setRtlSupport(true)
        #expect(NativeUIState.shared.isRTL == NativeUIState.deviceLanguageIsRTL())
    }
}
