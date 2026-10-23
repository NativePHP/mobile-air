package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Exact liveness/eviction semantics of the screen-token ledger across
 * every navigation shape the router can produce. Tokens are opaque
 * per-mount strings; only membership in the frame's bottom-up list
 * decides survival.
 */
class ScreenTokensTest {

    @Test
    fun pushRetainsBuriedTokensAndEvictsNothing() {
        val root = "a".repeat(32)
        val pushed = "b".repeat(32)

        assertEquals(
            emptyList<String>(),
            ScreenTokens.evicted(listOf(root), listOf(root, pushed)),
        )
    }

    @Test
    fun plainBackEvictsOnlyThePoppedTop() {
        val root = "a".repeat(32)
        val pushed = "b".repeat(32)

        assertEquals(
            listOf(pushed),
            ScreenTokens.evicted(listOf(root, pushed), listOf(root)),
        )
    }

    @Test
    fun popToMultiPopEvictsExactlyTheIntermediateMounts() {
        val feed = "f".repeat(32)
        val thread = "t".repeat(32)
        val reply = "r".repeat(32)

        // thread -> reply pushed over the feed, then popTo('/log'):
        // both popped tokens leave, the resumed feed token stays.
        assertEquals(
            listOf(thread, reply),
            ScreenTokens.evicted(listOf(feed, thread, reply), listOf(feed)),
        )
    }

    @Test
    fun replaceEvictsTheReplacedMountOnly() {
        val feed = "f".repeat(32)
        val old = "o".repeat(32)
        val fresh = "n".repeat(32)

        assertEquals(
            listOf(old),
            ScreenTokens.evicted(listOf(feed, old), listOf(feed, fresh)),
        )
    }

    @Test
    fun accountSwitchEvictsTheEntirePreviousLedger() {
        // Every mount is fresh: the first frame of the new account
        // evicts everything the old account had saved.
        val oldLedger = listOf("a".repeat(32), "b".repeat(32))
        val newLedger = listOf("x".repeat(32))

        assertEquals(
            oldLedger,
            ScreenTokens.evicted(oldLedger, newLedger),
        )
    }

    @Test
    fun freshOwnerOnTheSameUriNeverCollidesWithThePreviousMount() {
        // Random per-mount tokens: a fresh mount of the same URI is a
        // different token, so the previous owner's state is evicted —
        // never silently restored into the new screen.
        val feed = "f".repeat(32)
        val first = "1".repeat(32)
        val second = "2".repeat(32)

        assertEquals(
            listOf(first),
            ScreenTokens.evicted(listOf(feed, first), listOf(feed, second)),
        )
    }

    @Test
    fun popToNoOpReEnterEvictsNothing() {
        val feed = "f".repeat(32)
        val top = "t".repeat(32)

        // The list is unchanged when the router re-enters the top.
        assertEquals(
            emptyList<String>(),
            ScreenTokens.evicted(listOf(feed, top), listOf(feed, top)),
        )
    }

    @Test
    fun emptyCurrentListEvictsEverything() {
        // Defensive: a frame that carries no tokens at all (old PHP)
        // must not be treated as an eviction order — see the renderer,
        // which skips the ledger entirely for empty lists. When a
        // non-empty ledger IS diffed against an empty list, everything
        // goes, which is the honest reading of "no mounts live".
        val ledger = listOf("a".repeat(32))

        assertEquals(
            ledger,
            ScreenTokens.evicted(ledger, emptyList()),
        )
    }
}
