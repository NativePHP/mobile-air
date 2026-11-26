package com.nativephp.mobile.ui.nativerender

import com.nativephp.mobile.ui.nativerender.ChromeMorphTracker.NavStamp
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ChromeMorphTrackerTest {
    // Stands in for NativeUIBridge's navigation serial: every PHP navigation
    // publish moves it on and records what PHP staged for it.
    private var serial = 0L
    private fun nav(transition: String?) = NavStamp(++serial, transition)
    private fun noNewNav(transition: String?) = NavStamp(serial, transition)

    private fun ChromeMorphTracker.tabs(key: String, depth: Int, nav: NavStamp) =
        settle(key, depth, nav, canMorph = { from -> from.substringBefore('|') == key.substringBefore('|') }, honourStagedPop = true)

    private fun ChromeMorphTracker.stack(key: String, depth: Int, nav: NavStamp) =
        settle(key, depth, nav, canMorph = { true }, honourStagedPop = false)

    @Test
    fun firstScreenNeverMorphs() {
        assertFalse(ChromeMorphTracker().tabs("0|/docs", 0, nav("morph")))
    }

    @Test
    fun morphPushMorphsAndDefaultBackMorphsItBack() {
        val tracker = ChromeMorphTracker()
        tracker.tabs("0|/docs", 0, nav(null))

        assertTrue(tracker.tabs("0|/docs/plugins", 1, nav("morph")))
        // PHP stages its default back transition; the tracker remembers the push.
        assertTrue(tracker.tabs("0|/docs", 0, nav("slide_from_left")))
    }

    @Test
    fun decisionHoldsWhileTheKeyIsUnchanged() {
        val tracker = ChromeMorphTracker()
        tracker.tabs("0|/docs", 0, nav(null))
        assertTrue(tracker.tabs("0|/docs/plugins", 1, nav("morph")))

        // A later publish restages something else before the level changes.
        assertTrue(tracker.tabs("0|/docs/plugins", 1, nav("slide_from_left")))
    }

    @Test
    fun explicitBackTransitionWinsInTabs() {
        val tracker = ChromeMorphTracker()
        tracker.tabs("0|/docs", 0, nav(null))
        tracker.tabs("0|/docs/plugins", 1, nav("morph"))

        assertFalse(tracker.tabs("0|/docs", 0, nav("fade")))
    }

    @Test
    fun plainPushAndItsPopDoNotMorph() {
        val tracker = ChromeMorphTracker()
        tracker.tabs("0|/docs", 0, nav(null))
        tracker.tabs("0|/docs/plugins", 1, nav("morph"))

        assertFalse(tracker.tabs("0|/docs/plugins/intro", 2, nav("slide_from_right")))
        assertFalse(tracker.tabs("0|/docs/plugins", 1, nav("slide_from_left")))
        // The level below was still entered by morph.
        assertTrue(tracker.tabs("0|/docs", 0, nav("slide_from_left")))
    }

    @Test
    fun tabSwitchNeverMorphs() {
        val tracker = ChromeMorphTracker()
        tracker.tabs("0|/docs", 0, nav(null))

        assertFalse(tracker.tabs("1|/videos", 0, nav("morph")))
    }

    @Test
    fun morphMemorySurvivesATabRoundTrip() {
        val tracker = ChromeMorphTracker()
        tracker.tabs("0|/docs", 0, nav(null))
        assertTrue(tracker.tabs("0|/docs/plugins", 1, nav("morph")))

        assertFalse(tracker.tabs("1|/videos", 0, nav("fade")))
        assertFalse(tracker.tabs("0|/docs/plugins", 1, nav("fade")))

        assertTrue(tracker.tabs("0|/docs", 0, nav("slide_from_left")))
    }

    @Test
    fun staleStagedMorphDoesNotMorphALaterChange() {
        val tracker = ChromeMorphTracker()
        tracker.tabs("0|/docs", 0, nav(null))
        assertTrue(tracker.tabs("0|/docs/plugins", 1, nav("morph")))

        // A same-depth change with no new navigation behind it (a redirect)
        // still sees "morph" staged, but that morph was already used.
        assertFalse(tracker.tabs("0|/docs/other", 1, noNewNav("morph")))
    }

    @Test
    fun stackPopMorphsBackWhateverIsStaged() {
        val tracker = ChromeMorphTracker()
        tracker.stack("/a", 0, nav(null))
        assertTrue(tracker.stack("/b", 1, nav("morph")))

        // The stack pops locally before PHP stages anything.
        assertTrue(tracker.stack("/a", 0, noNewNav("morph")))
    }

    @Test
    fun stackPushSeesTheNavigationEvenWhenThePathMovesAPassLater() {
        val tracker = ChromeMorphTracker()
        tracker.stack("/a", 0, nav(null))

        // The publish lands first; the coordinator pushes the path afterwards.
        val push = nav("morph")
        assertFalse(tracker.stack("/a", 0, push))
        assertTrue(tracker.stack("/b", 1, push))
    }

    @Test
    fun quickDoubleBackAfterAMorphPushOnlyMorphsTheFirstPop() {
        val tracker = ChromeMorphTracker()
        tracker.stack("/x", 0, nav(null))
        tracker.stack("/a", 1, nav("slide_from_right"))
        tracker.stack("/b", 2, nav("morph"))

        // Two local pops before PHP republishes: the staged morph is stale.
        assertTrue(tracker.stack("/a", 1, noNewNav("morph")))
        assertFalse(tracker.stack("/x", 0, noNewNav("morph")))
    }

    @Test
    fun stackPopOfSlidLevelDoesNotMorphOnStaleStagedMorph() {
        val tracker = ChromeMorphTracker()
        tracker.stack("/a", 0, nav(null))
        tracker.stack("/b", 1, nav("slide_from_right"))

        assertFalse(tracker.stack("/a", 0, noNewNav("morph")))
    }
}
