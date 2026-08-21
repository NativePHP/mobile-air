package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class NativeUIStateTest {

    @Test
    fun baseLanguageExtractsTheIsoCodeFromAVariant() {
        assertEquals("ar", NativeUIState.baseLanguage("ar-SA"))
        assertEquals("ar", NativeUIState.baseLanguage("ar-AE"))
        assertEquals("he", NativeUIState.baseLanguage("he-IL"))
        assertEquals("fa", NativeUIState.baseLanguage("fa-IR"))
        assertEquals("ur", NativeUIState.baseLanguage("ur-PK"))
        assertEquals("en", NativeUIState.baseLanguage("en-US"))
        assertEquals("fr", NativeUIState.baseLanguage("fr-FR"))
        assertEquals("ar", NativeUIState.baseLanguage("ar"))
        assertEquals("ar", NativeUIState.baseLanguage("AR-sa"))
    }

    @Test
    fun rtlLocalesAreDetectedThroughTheirBaseLanguage() {
        listOf("ar", "ar-SA", "ar-AE", "he", "he-IL", "fa", "fa-IR", "ur", "ur-PK")
            .forEach { assertTrue(it, NativeUIState.isRtlLanguage(it)) }
    }

    @Test
    fun ltrLocalesAreNotRtl() {
        listOf("en", "en-US", "fr-FR", "en-SA", "de", "es")
            .forEach { assertFalse(it, NativeUIState.isRtlLanguage(it)) }
    }

    @Test
    fun effectiveRtlRequiresBothOptInAndAnRtlLocale() {
        // Scenario A: enabled + Arabic -> RTL
        assertTrue(NativeUIState.isRtlLanguage("ar-SA"))
        // Scenario B: enabled + English -> LTR (the locale is not RTL)
        assertFalse(NativeUIState.isRtlLanguage("en-US"))
        // Scenario C: the opt-in flag is applied by setRtlSupport; without it
        // the state stays LTR regardless of locale.
        NativeUIState.setRtlSupport(false)
        assertFalse(NativeUIState.isRTL.value)
        NativeUIState.setRtlSupport(true)
        assertEquals(NativeUIState.deviceLanguageIsRTL(), NativeUIState.isRTL.value)
    }
}
