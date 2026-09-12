package com.nativephp.mobile.ui.nativerender

import androidx.compose.animation.BoundsTransform
import androidx.compose.animation.ExperimentalSharedTransitionApi
import androidx.compose.animation.core.Easing
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.LinearOutSlowInEasing
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.FiniteAnimationSpec
import androidx.compose.animation.core.FastOutLinearInEasing
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.core.VisibilityThreshold
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Rect

/**
 * Shared-element morph for nodes carrying a `ref` — the Android half of the
 * feature iOS implements in `NodeHeroModifier`.
 *
 * Identity is the element's `ref`. Two screens naming an element the same
 * thing morph it between them during a `view_transition` navigation. A ref
 * that exists only as a test handle and must never travel opts out with
 * `morph="none"`.
 *
 * Compose does the heavy lifting here that iOS does not: `SharedTransitionLayout`
 * wrapped around the screen-swapping `AnimatedContent` matches keys across the
 * two panes and animates the bounds itself. There is no source/slave role to
 * assign, no arming, and no timing race — all of which the iOS side needs.
 *
 * A ref present on only one pane never matches and renders normally, so
 * unmatched elements are inert without any pairing set.
 *
 * ## Parity note: `morph="position"` / `morph="size"`
 *
 * iOS maps these onto `MatchedGeometryProperties.position` / `.size`, which
 * share only half the geometry. Compose's shared-element API always animates
 * the full bounds and exposes no equivalent, so both values fall back to the
 * default full-bounds morph here. That is a real behavioural difference
 * between the platforms, not an oversight — the alternative was to invent an
 * approximation that looks like the iOS one without matching it.
 */
@OptIn(ExperimentalSharedTransitionApi::class)
@Composable
fun Modifier.heroMorph(node: NativeUINode): Modifier {
    val ref = node.props.getString("ref", "")
    val mode = node.props.getString("morph", "frame")

    // Fast path — the overwhelming majority of nodes. No ref, an explicit
    // opt-out, or no host providing the scopes (previews, tests, any tree
    // rendered outside NativeUIContent).
    if (ref.isEmpty() || mode == "none") return this

    val sharedScope = LocalSharedTransitionScope.current ?: return this
    val animatedScope = LocalAnimatedVisibilityScope.current ?: return this

    val spec = node.boundsSpec()

    return with(sharedScope) {
        this@heroMorph.sharedBounds(
            sharedContentState = rememberSharedContentState(key = ref),
            animatedVisibilityScope = animatedScope,
            boundsTransform = BoundsTransform { _, _ -> spec }
        )
    }
}

/**
 * `morph-duration` (ms) and `morph-easing` for one element, falling back to a
 * 350ms ease-in-out that matches iOS's shared view-transition pace so an
 * untuned morph looks the same on both platforms.
 *
 * Deliberately distinct from `animate-duration` / `animate-easing`, which
 * drive state-change transforms: an element may want a 200ms press response
 * and a 600ms morph.
 */
@OptIn(ExperimentalSharedTransitionApi::class)
private fun NativeUINode.boundsSpec(): FiniteAnimationSpec<Rect> {
    val duration = props.getFloat("morph_duration", 0f)
    val easing = props.getString("morph_easing", "")

    if (easing == "spring") {
        return spring(
            dampingRatio = Spring.DampingRatioLowBouncy,
            stiffness = Spring.StiffnessMediumLow,
            visibilityThreshold = Rect.VisibilityThreshold
        )
    }

    return tween(
        durationMillis = if (duration > 0f) duration.toInt() else 350,
        easing = when (easing) {
            "linear" -> LinearEasing
            "ease-in" -> FastOutLinearInEasing
            "ease-out" -> LinearOutSlowInEasing
            else -> FastOutSlowInEasing
        }
    )
}
