package com.nativephp.mobile.ui.nativerender

import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class KeyboardFocusPolicyTest {
    private val fieldA = Any()
    private val fieldB = Any()
    private var clearFocusCalls = 0

    // The policy is a singleton, so leave it clean for the next test.
    // Taking ownership first means the blur clears whichever field a
    // test left focused.
    @After
    fun resetPolicy() {
        KeyboardFocusPolicy.fieldFocused(fieldA, keepsFocus = false)
        KeyboardFocusPolicy.fieldBlurred(fieldA)
        KeyboardFocusPolicy.clearFocus = null
    }

    @Test
    fun interactiveTapClearsFocusWhenNoFieldIsFocused() {
        registerClearFocus()

        KeyboardFocusPolicy.dismissForInteractiveTap()

        assertEquals(1, clearFocusCalls)
    }

    @Test
    fun interactiveTapClearsFocusWhenTheFocusedFieldDoesNotKeepFocus() {
        registerClearFocus()
        KeyboardFocusPolicy.fieldFocused(fieldA, keepsFocus = false)

        KeyboardFocusPolicy.dismissForInteractiveTap()

        assertFalse(KeyboardFocusPolicy.focusedFieldKeepsFocus)
        assertEquals(1, clearFocusCalls)
    }

    @Test
    fun interactiveTapLeavesFocusAloneWhenTheFocusedFieldKeepsFocus() {
        registerClearFocus()
        KeyboardFocusPolicy.fieldFocused(fieldA, keepsFocus = true)

        KeyboardFocusPolicy.dismissForInteractiveTap()

        assertTrue(KeyboardFocusPolicy.focusedFieldKeepsFocus)
        assertEquals(0, clearFocusCalls)
    }

    @Test
    fun interactiveTapIsANoOpWithoutAClearFocusHook() {
        KeyboardFocusPolicy.clearFocus = null

        KeyboardFocusPolicy.dismissForInteractiveTap()

        assertEquals(0, clearFocusCalls)
    }

    @Test
    fun lateBlurFromThePreviousFieldDoesNotClearTheNewFieldsFlag() {
        registerClearFocus()
        KeyboardFocusPolicy.fieldFocused(fieldA, keepsFocus = false)
        KeyboardFocusPolicy.fieldFocused(fieldB, keepsFocus = true)
        KeyboardFocusPolicy.fieldBlurred(fieldA)

        KeyboardFocusPolicy.dismissForInteractiveTap()

        assertTrue(KeyboardFocusPolicy.focusedFieldKeepsFocus)
        assertEquals(0, clearFocusCalls)
    }

    @Test
    fun blurFromTheOwningFieldClearsTheFlag() {
        registerClearFocus()
        KeyboardFocusPolicy.fieldFocused(fieldB, keepsFocus = true)
        KeyboardFocusPolicy.fieldBlurred(fieldB)

        KeyboardFocusPolicy.dismissForInteractiveTap()

        assertFalse(KeyboardFocusPolicy.focusedFieldKeepsFocus)
        assertEquals(1, clearFocusCalls)
    }

    @Test
    fun blurFromAFieldThatNeverHadFocusChangesNothing() {
        registerClearFocus()
        KeyboardFocusPolicy.fieldBlurred(fieldA)

        KeyboardFocusPolicy.dismissForInteractiveTap()

        assertFalse(KeyboardFocusPolicy.focusedFieldKeepsFocus)
        assertEquals(1, clearFocusCalls)

        KeyboardFocusPolicy.fieldFocused(fieldB, keepsFocus = true)
        KeyboardFocusPolicy.fieldBlurred(fieldA)

        KeyboardFocusPolicy.dismissForInteractiveTap()

        assertTrue(KeyboardFocusPolicy.focusedFieldKeepsFocus)
        assertEquals(1, clearFocusCalls)
    }

    private fun registerClearFocus() {
        KeyboardFocusPolicy.clearFocus = { clearFocusCalls++ }
    }
}
