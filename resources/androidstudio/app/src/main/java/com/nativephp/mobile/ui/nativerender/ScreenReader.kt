package com.nativephp.mobile.ui.nativerender

import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.view.View
import android.view.accessibility.AccessibilityNodeInfo
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.node.RootForTest
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.getAllSemanticsNodes
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import java.lang.ref.WeakReference

/**
 * The two things PHP can ask of TalkBack directly, through the
 * `Accessibility.Announce` and `Accessibility.Focus` bridge functions.
 * Both run on the main thread.
 *
 * Neither uses `View.announceForAccessibility`, which Android 16 deprecated
 * because accessibility services may ignore it:
 *
 * - An announcement is the text of a live region at the root of the screen
 *   ([Announcer]), which TalkBack reads when it changes.
 * - A focus move performs `ACTION_ACCESSIBILITY_FOCUS` on the node whose
 *   `testTag` is the requested `ref`, the action TalkBack itself sends when
 *   the user touches an element. Compose honours it only while touch
 *   exploration is on, so it never moves input focus or affects a
 *   hardware keyboard.
 */
object ScreenReader {
    private var message by mutableStateOf("")
    private var assertive by mutableStateOf(false)
    private var announcements = 0

    private var root: WeakReference<View>? = null
    private var pendingRef: String? = null
    private var pendingUntil = 0L
    private val handler = Handler(Looper.getMainLooper())
    private val applyWithoutRender = Runnable { applyPendingFocus() }

    /** How long a focus request waits for its element to be rendered. */
    private const val FOCUS_WAIT_MS = 1_000L

    /** How long a focus request waits for the render its handler makes. */
    private const val RENDER_WAIT_MS = 500L

    fun announce(text: String, isAssertive: Boolean) {
        announcements++
        assertive = isAssertive
        // A zero-width space alternates on repeats, so announcing the same
        // text twice still changes the live region.
        message = if (announcements % 2 == 0) text else "$text\u200B"
    }

    /**
     * Moves TalkBack to the element with this `ref` once the handler that
     * asked has rendered, so the element is read in its new state (with the
     * error the handler just gave it, say), or as it is when no render
     * comes. Waits for the element when that render doesn't show it yet.
     */
    fun focus(ref: String) {
        pendingRef = ref
        pendingUntil = SystemClock.uptimeMillis() + FOCUS_WAIT_MS
        handler.removeCallbacks(applyWithoutRender)
        handler.postDelayed(applyWithoutRender, RENDER_WAIT_MS)
    }

    /** Called by `NativeUIContent` after each render is drawn. */
    internal fun applyPendingFocus() {
        val ref = pendingRef ?: return
        if (SystemClock.uptimeMillis() > pendingUntil) {
            pendingRef = null
            return
        }
        val view = root?.get() ?: return
        if (focusNode(view, ref)) pendingRef = null
    }

    internal fun attach(view: View) {
        root = WeakReference(view)
    }

    internal fun detach(view: View) {
        if (root?.get() === view) root = null
    }

    private fun focusNode(view: View, ref: String): Boolean {
        val owner = (view as? RootForTest)?.semanticsOwner ?: return false
        val provider = view.accessibilityNodeProvider ?: return false
        val target = owner.getAllSemanticsNodes(mergingEnabled = false)
            .firstOrNull { it.config.getOrNull(SemanticsProperties.TestTag) == ref }
            ?: return false

        // A node inside an element that merges it (a tappable pressable's
        // text, say) is read as part of that element, so focus the nearest
        // one. Compose would focus the bare node otherwise.
        var node: SemanticsNode? = if (target.config.isMergingSemanticsOfDescendants) {
            target
        } else {
            generateSequence(target.parent) { it.parent }
                .firstOrNull { it.config.isMergingSemanticsOfDescendants } ?: target
        }

        // A node inside a labelled element is not exposed on its own: focus
        // the element that clears it, and never a container beyond that,
        // which would read a whole region.
        while (node != null) {
            // Hidden (`a11y-hidden`, or behind an on-screen modal): never move
            // TalkBack there; the element may be reachable after the next render.
            if (node.config.contains(A11yHidden)) return false
            // Already there: Compose refuses to focus it again.
            if (provider.createAccessibilityNodeInfo(node.id)?.isAccessibilityFocused == true) return true
            if (provider.performAction(node.id, AccessibilityNodeInfo.ACTION_ACCESSIBILITY_FOCUS, null)) {
                return true
            }
            node = node.parent?.takeIf { it.config.isMergingSemanticsOfDescendants || it.config.isClearingSemantics }
        }
        return false
    }

    /**
     * The root live region that speaks [announce]'s messages, hosted by
     * `MainActivity` so it works under either renderer. The text is cleared a few seconds
     * later so swiping through the screen doesn't land on an old message.
     */
    @Composable
    internal fun Announcer() {
        val text = message
        LaunchedEffect(text) {
            if (text.isNotEmpty()) {
                delay(5_000)
                if (message == text) message = ""
            }
        }
        // Always composed: TalkBack speaks a live region when its text
        // changes, not when the region first appears.
        Box(
            Modifier
                .size(1.dp)
                // Its own element, so a merging ancestor doesn't fold the
                // text into itself and lose the live region.
                .semantics(mergeDescendants = true) {
                    if (text.isNotEmpty()) contentDescription = text
                    liveRegion = if (assertive) LiveRegionMode.Assertive else LiveRegionMode.Polite
                },
        )
    }
}
