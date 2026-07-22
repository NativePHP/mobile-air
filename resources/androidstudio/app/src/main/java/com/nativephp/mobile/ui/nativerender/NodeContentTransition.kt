package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.SizeTransform
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

/**
 * Compose counterpart of iOS's `.contentTransition(...)`, driven by the
 * `content_transition` prop:
 *   - `numeric` — Compose has no built-in `numericText` equivalent, so this
 *     approximates it with the standard rolling-counter pattern: the text
 *     slides up (and fades) when the value increases, down when it decreases.
 *     Direction comes from parsing both sides as numbers; non-numeric text
 *     always rolls up.
 *   - `opacity` / `interpolate` — crossfade. (`interpolate` is a SwiftUI
 *     glyph morph with no Compose analog; crossfade is the closest fit.)
 *
 * The node's own modifier chain (bg, padding, gestures, flex sizing) is
 * applied ONCE to the AnimatedContent container — the entering/exiting frames
 * render with an empty modifier so only the text content moves, not the box.
 */
@Composable
fun NodeContentTransition(node: NativeUINode, kind: String, modifier: Modifier) {
    val renderer = NativeRendererRegistry.get(node.type)
    val text = node.props.getString("text")
    val durationMs = node.props.getFloat("animate-duration", 0f)
        .let { if (it > 0f) it.toInt() else 300 }

    AnimatedContent(
        targetState = text,
        modifier = modifier,
        transitionSpec = {
            if (kind == "numeric") {
                val old = numericValue(initialState)
                val new = numericValue(targetState)
                val up = old == null || new == null || new >= old
                val enter = slideInVertically(tween(durationMs)) { if (up) it else -it } +
                    fadeIn(tween(durationMs))
                val exit = slideOutVertically(tween(durationMs)) { if (up) -it else it } +
                    fadeOut(tween(durationMs))
                // clip = false — the rolling glyphs may momentarily overshoot
                // the text's own bounds; clipping snips the top/bottom of the
                // digits mid-roll.
                (enter togetherWith exit).using(SizeTransform(clip = false))
            } else {
                fadeIn(tween(durationMs)) togetherWith fadeOut(tween(durationMs))
            }
        },
        label = "content_transition"
    ) { frameText ->
        // AnimatedContent re-invokes this lambda with the OLD state for the
        // exiting frame, but `node` already carries the new text — swap the
        // text prop back so the outgoing frame keeps showing what it showed.
        val frameNode = if (frameText == text) node
            else node.copy(props = node.props.with("text", frameText))
        if (renderer != null) {
            renderer.Render(frameNode, Modifier)
        } else {
            DefaultContainerNode(frameNode, Modifier)
        }
    }
}

/** Lenient numeric parse for roll direction — tolerates "1,204" / "87%". */
private fun numericValue(s: String): Double? =
    s.replace(",", "").removeSuffix("%").trim().toDoubleOrNull()
