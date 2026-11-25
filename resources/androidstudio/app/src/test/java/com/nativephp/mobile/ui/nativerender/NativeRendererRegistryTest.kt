package com.nativephp.mobile.ui.nativerender

import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class NativeRendererRegistryTest {
    private val logged = mutableListOf<String>()
    private lateinit var originalLogError: (String) -> Unit

    @Before
    fun captureLogs() {
        NativeRendererRegistry.resetForTesting()
        originalLogError = NativeRendererRegistry.logError
        NativeRendererRegistry.logError = { message -> logged.add(message) }
    }

    @After
    fun restore() {
        NativeRendererRegistry.logError = originalLogError
        NativeRendererRegistry.resetForTesting()
    }

    @Test
    fun anotherOwnerCannotReplaceARenderer() {
        val core = NodeRenderer { _, _ -> }

        assertTrue(NativeRendererRegistry.register("native_root_tabs", NativeRendererRegistry.CORE_OWNER, core))
        assertFalse(NativeRendererRegistry.register("native_root_tabs", "acme/tabs", NodeRenderer { _, _ -> }))

        assertSame(core, NativeRendererRegistry.get("native_root_tabs"))
        assertEquals(NativeRendererRegistry.CORE_OWNER, NativeRendererRegistry.ownerOf("native_root_tabs"))
        assertEquals(
            listOf("Refused the renderer for 'native_root_tabs' from acme/tabs: it is already registered by core."),
            logged,
        )
    }

    @Test
    fun theOldSignatureRegistersUnderUnknownAndFollowsTheSameRules() {
        val core = NodeRenderer { _, _ -> }
        NativeRendererRegistry.register("native_root_stack", NativeRendererRegistry.CORE_OWNER, core)

        NativeRendererRegistry.register("native_root_stack", NodeRenderer { _, _ -> })
        NativeRendererRegistry.register("legacy_widget", NodeRenderer { _, _ -> })

        assertSame(core, NativeRendererRegistry.get("native_root_stack"))
        assertEquals(NativeRendererRegistry.UNKNOWN_OWNER, NativeRendererRegistry.ownerOf("legacy_widget"))
        assertEquals(1, logged.size)
    }

    @Test
    fun theSameOwnerRegisteringAgainKeepsTheFirstRendererQuietly() {
        val first = NodeRenderer { _, _ -> }

        assertTrue(NativeRendererRegistry.register("acme_widget", "acme/widgets", first))
        assertTrue(NativeRendererRegistry.register("acme_widget", "acme/widgets", NodeRenderer { _, _ -> }))

        assertSame(first, NativeRendererRegistry.get("acme_widget"))
        assertTrue(logged.isEmpty())
    }

    @Test
    fun aLockedRegistryRefusesNewTypesButNotARepeat() {
        NativeRendererRegistry.register("acme_widget", "acme/widgets", NodeRenderer { _, _ -> })
        NativeRendererRegistry.lockRegistrations()

        assertFalse(NativeRendererRegistry.register("late_widget", "acme/widgets", NodeRenderer { _, _ -> }))
        assertNull(NativeRendererRegistry.get("late_widget"))
        assertTrue(NativeRendererRegistry.register("acme_widget", "acme/widgets", NodeRenderer { _, _ -> }))
        assertEquals(
            listOf("Refused the renderer for 'late_widget' from acme/widgets: renderers can only be registered during startup."),
            logged,
        )
    }
}
