package com.nativephp.mobile.ui.nativerender

import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotSame
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
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
    fun aSameKeyReplacementYieldsADistinctEntryInstance() {
        // The premise of the reselect gate's identity check
        // (shouldFireReselectAfterScroll): a re-registration under the
        // same key produces a NEW Entry object, so `get(key) ===
        // capturedEntry` distinguishes the scrolled container from an
        // abandoned one that a newer composition replaced mid-animation.
        NativeScrollTargetRegistry.register("feed", Any(), { false }, {})
        val first = NativeScrollTargetRegistry.get("feed")

        NativeScrollTargetRegistry.register("feed", Any(), { true }, {})

        val current = NativeScrollTargetRegistry.get("feed")
        assertNotSame(first, current)
    }

    // ── Live availability predicate ──────────────

    @Test
    fun aRegisteredTargetIsAvailableByDefault() {
        NativeScrollTargetRegistry.register("feed", Any(), { true }, {})

        assertNotNull(NativeScrollTargetRegistry.get("feed"))
    }

    @Test
    fun anUnavailableTargetBehavesExactlyLikeAMissingOne() {
        // The pager gap: a hidden NEIGHBOR page stays composed and its
        // registration is live — but the page is not on screen. Both
        // the tap-time and the post-scroll reads go through get(), so
        // false here closes the gap at both instants.
        NativeScrollTargetRegistry.register("feed", Any(), { false }, {}, isAvailable = { false })

        assertNull(NativeScrollTargetRegistry.get("feed"))
    }

    @Test
    fun availabilityIsLiveAndCanFlipBackAndForth() {
        var onScreen = false
        NativeScrollTargetRegistry.register("feed", Any(), { true }, {}, isAvailable = { onScreen })

        assertNull(NativeScrollTargetRegistry.get("feed"))

        onScreen = true
        assertNotNull(NativeScrollTargetRegistry.get("feed"))

        onScreen = false
        assertNull(NativeScrollTargetRegistry.get("feed"))
    }

    @Test
    fun unregisterKeepsIdentitySemanticsWhileTheTargetIsUnavailable() {
        val owner = Any()
        var onScreen = false
        NativeScrollTargetRegistry.register("feed", owner, { true }, {}, isAvailable = { onScreen })

        // Unavailable is not unregistered: the entry is still there,
        // only hidden from get().
        assertNull(NativeScrollTargetRegistry.get("feed"))

        // A stale owner still cannot remove it…
        NativeScrollTargetRegistry.unregister("feed", Any())
        onScreen = true
        assertNotNull(NativeScrollTargetRegistry.get("feed"))

        // …but the real owner can.
        NativeScrollTargetRegistry.unregister("feed", owner)
        assertNull(NativeScrollTargetRegistry.get("feed"))
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
