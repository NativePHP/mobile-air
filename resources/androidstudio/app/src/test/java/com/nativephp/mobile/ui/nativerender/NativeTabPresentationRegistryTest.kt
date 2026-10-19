package com.nativephp.mobile.ui.nativerender

import android.content.Context
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

@Suppress("UNUSED_PARAMETER")
private fun pluginInitializerCompilationFixture(context: Context) {
    NativeTabPresentationRegistry.register("fixture:initializer") {}
}

class NativeTabPresentationRegistryTest {
    @After
    fun resetRegistry() = NativeTabPresentationRegistry.resetForTesting()

    @Test
    fun firstRegistrationWins() {
        assertTrue(NativeTabPresentationRegistry.register("acme:floating") {})
        assertFalse(NativeTabPresentationRegistry.register("acme:floating") {})
        assertNotNull(NativeTabPresentationRegistry.presenter("acme:floating"))
        assertNull(NativeTabPresentationRegistry.presenter("missing"))
    }

    @Test
    fun configurationProvidesTypedValues() {
        val configuration = NativeTabPresentationConfiguration.from(
            mapOf("radius" to 24, "glass" to true, "labels" to listOf("Home", "Profile")),
        )
        assertEquals(24.0, configuration.number("radius"))
        assertEquals(true, configuration.boolean("glass"))
        assertEquals(listOf("Home", "Profile"), configuration.strings("labels"))
    }

    @Test
    fun contextOnlyActivatesKnownDescriptors() {
        val activated = mutableListOf<String>()
        val context = NativeTabPresentationContext(
            tabs = listOf(NativeTabDescriptor("home", "Home", "Home", "home", null, false, NativeTabRole.NAVIGATION)),
            selectedTabIdentifier = "home",
            configuration = NativeTabPresentationConfiguration.from(emptyMap()),
            isHidden = false,
            activation = activated::add,
        )
        context.activate("unknown")
        context.activate("home")
        assertEquals(listOf("home"), activated)
    }

    @Test
    fun descriptorIdentifiersDisambiguateDuplicateSourceIdentifiers() {
        assertEquals("home#0", nativeTabPresentationIdentifier("home", 0))
        assertEquals("home#1", nativeTabPresentationIdentifier("home", 1))
    }

    @Test
    fun pluginInitializerCanAccessPublicRegistry() {
        val initializer: (Context) -> Unit = ::pluginInitializerCompilationFixture
        assertNotNull(initializer)
    }
}
