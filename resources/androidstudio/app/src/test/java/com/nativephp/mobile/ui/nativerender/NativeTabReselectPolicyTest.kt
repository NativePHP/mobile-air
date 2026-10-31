package com.nativephp.mobile.ui.nativerender

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class NativeTabReselectPolicyTest {
    @Test
    fun selectedNavigationTabCanReselect() {
        assertTrue(nativeTabCanReselect(1, 1, 1, false, true))
    }

    @Test
    fun switchingTabsIsNotAReselect() {
        assertFalse(nativeTabCanReselect(0, 0, 1, false, true))
    }

    @Test
    fun pendingNavigationCannotReselectTheOldScreen() {
        assertFalse(nativeTabCanReselect(0, 0, 0, true, true))
        assertFalse(nativeTabCanReselect(1, 0, 1, false, true))
        assertFalse(nativeTabCanReselect(1, 0, 0, false, true))
    }

    @Test
    fun searchAndActionTabsDoNotReselect() {
        assertFalse(nativeTabCanReselect(0, 0, 0, false, false))
    }
}
