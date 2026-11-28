package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class ScreenTokensTest {
    private val list = "a".repeat(32)
    private val detail = "b".repeat(32)
    private val fresh = "c".repeat(32)

    @Test
    fun parsesNewlineSeparatedTokensBottomFirst() {
        assertEquals(listOf(list, detail), ScreenTokens.parse("$list\n$detail"))
        assertEquals(emptyList<String>(), ScreenTokens.parse(""))
    }

    @Test
    fun pushKeepsTheScreenUnderneath() {
        assertEquals(emptyList<String>(), ScreenTokens.evicted(listOf(list), listOf(list, detail)))
    }

    @Test
    fun backDropsOnlyThePoppedScreen() {
        assertEquals(listOf(detail), ScreenTokens.evicted(listOf(list, detail), listOf(list)))
    }

    @Test
    fun replacingTheSameUriDropsTheOldMount() {
        assertEquals(listOf(detail), ScreenTokens.evicted(listOf(list, detail), listOf(list, fresh)))
    }

    @Test
    fun aRepublishOfTheSameStackDropsNothing() {
        assertEquals(emptyList<String>(), ScreenTokens.evicted(listOf(list, detail), listOf(list, detail)))
    }

    @Test
    fun aWhollyNewStackDropsEverything() {
        assertEquals(listOf(list, detail), ScreenTokens.evicted(listOf(list, detail), listOf(fresh)))
    }

    @Test
    fun findsTheTokenForARouterDepth() {
        val tokens = listOf(list, detail)

        assertEquals(list, ScreenTokens.atDepth(tokens, 1))
        assertEquals(detail, ScreenTokens.atDepth(tokens, 2))
        assertNull(ScreenTokens.atDepth(tokens, 3))
        assertNull(ScreenTokens.atDepth(tokens, 0))
    }
}
