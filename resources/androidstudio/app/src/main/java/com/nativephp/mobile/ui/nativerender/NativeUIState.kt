package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.unit.LayoutDirection
import java.util.Locale

/**
 * Shared native UI state for opt-in RTL support. Mirrors iOS
 * `NativeUIState` and the PHP `Native\Mobile\Support\Rtl` language list.
 *
 * Holds the developer's opt-in `rtlSupport` flag (pushed from PHP via
 * `_meta.rtl_support` in the `UI.SetRtlSupport` bridge call) and the
 * derived effective `isRTL`, which is:
 *
 *     isRTL = rtlSupport && deviceLanguageIsRTL
 *
 * The device locale is authoritative for the native shell; this is what
 * allows the shell to follow `ar-SA` even when the Laravel locale is `en`.
 */
object NativeUIState {

    /** RTL languages by base ISO 639-1 code (kept in lockstep with PHP). */
    private val rtlLanguages = setOf(
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
    )

    /** Developer opt-in from `nativephp.rtl_support`. */
    val rtlSupport = mutableStateOf(false)

    /** Effective layout direction for the native shell. */
    val isRTL = mutableStateOf(false)

    /** Update the opt-in flag from the `UI.SetRtlSupport` bridge call. */
    fun setRtlSupport(enabled: Boolean) {
        rtlSupport.value = enabled
        recompute()
    }

    /** Recompute the effective direction from the current device locale. */
    fun recompute() {
        isRTL.value = rtlSupport.value && deviceLanguageIsRTL()
    }

    /** Compose layout direction for the effective RTL state. */
    val layoutDirection: LayoutDirection
        get() = if (isRTL.value) LayoutDirection.Rtl else LayoutDirection.Ltr

    /** Whether the device's active language is an RTL language. */
    fun deviceLanguageIsRTL(): Boolean {
        return isRtlLanguage(deviceLanguage())
    }

    /** The device's active language, as a base ISO 639-1 code. */
    fun deviceLanguage(): String {
        return Locale.getDefault().language.ifEmpty { "en" }.lowercase(Locale.ROOT)
    }

    /** Whether a locale (or BCP 47 tag) is an RTL language, resolving
     *  through its base language. Pure and deterministic — testable. */
    fun isRtlLanguage(locale: String): Boolean {
        return rtlLanguages.contains(baseLanguage(locale))
    }

    /** Extract the base language from a BCP 47 tag (e.g. `ar-SA` -> `ar`). */
    fun baseLanguage(locale: String): String {
        return locale.substringBefore('-').substringBefore('_').lowercase(Locale.ROOT)
    }
}
