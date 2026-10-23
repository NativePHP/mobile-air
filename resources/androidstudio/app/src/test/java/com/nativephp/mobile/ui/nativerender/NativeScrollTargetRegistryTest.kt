package com.nativephp.mobile.ui.nativerender

import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test

class NativeScrollTargetRegistryTest {
    @After
    fun tearDown() {
        NativeScrollTargetRegistry.clear()
    }

    @Test
    fun getReturnsTheRegisteredEntry() {
        val owner = Any()
        NativeScrollTargetRegistry.register("feed", owner, { true }, {})

        val entry = NativeScrollTargetRegistry.get("feed")
        assertEquals("feed", entry?.key)
        assertSame(owner, entry?.owner)
        assertTrue(entry!!.isAtTop())
    }

    @Test
    fun getReturnsNullForAnUnregisteredKey() {
        assertNull(NativeScrollTargetRegistry.get("missing"))
    }

    @Test
    fun registerReplacesAnEntryUnderTheSameKey() {
        NativeScrollTargetRegistry.register("feed", Any(), { false }, {})
        NativeScrollTargetRegistry.register("feed", Any(), { true }, {})

        assertTrue(NativeScrollTargetRegistry.get("feed")!!.isAtTop())
    }

    @Test
    fun unregisterRemovesOnlyTheEntryOwnedByTheSameOwner() {
        val owner = Any()
        NativeScrollTargetRegistry.register("feed", owner, { true }, {})

        // A stale disposal carrying a different owner token (e.g. the
        // previous composition's onDispose running after a newer
        // composition already re-registered the same key) must not
        // remove the live entry.
        NativeScrollTargetRegistry.unregister("feed", Any())
        assertNotNull(NativeScrollTargetRegistry.get("feed"))

        NativeScrollTargetRegistry.unregister("feed", owner)
        assertNull(NativeScrollTargetRegistry.get("feed"))
    }

    @Test
    fun unregisterOfAMissingKeyIsASafeNoOp() {
        NativeScrollTargetRegistry.unregister("missing", Any()) // must not throw
        assertNull(NativeScrollTargetRegistry.get("missing"))
    }

    @Test
    fun scrollToTopRunsTheRegisteredSuspendBlock() = runBlocking {
        var ran = false
        val owner = Any()
        NativeScrollTargetRegistry.register("feed", owner, { false }) { ran = true }

        NativeScrollTargetRegistry.get("feed")!!.scrollToTop()
        assertTrue(ran)
        assertFalse(NativeScrollTargetRegistry.get("feed")!!.isAtTop())
    }
}
