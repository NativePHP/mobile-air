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
import androidx.compose.animation.AnimatedVisibilityScope
import androidx.compose.animation.EnterTransition
import androidx.compose.animation.ExitTransition
import androidx.compose.animation.SharedTransitionScope
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Rect

/**
 * Shared-element morph for nodes carrying a `ref` — the Android half of the
 * feature iOS implements in `NodeHeroModifier`.
 *
 * Identity is the element's `ref`. Two screens naming an element the same
 * thing morph it between them during a `morph` navigation. A ref
 * that exists only as a test handle and must never travel opts out with
 * `morph="none"`.
 *
 * Compose does the heavy lifting here that iOS does not: `SharedTransitionLayout`
 * wrapped around the screen-swapping `AnimatedContent` matches keys across the
 * two panes and animates the bounds itself. There is no overlay to manage, no
 * frames to collect, and no timing race — all of which the iOS side needs.
 *
 * A ref present on only one pane never matches and renders normally, so
 * unmatched elements are inert without any pairing set.
 *
 * ## Parity note: `morph="position"` / `morph="size"`
 *
 * iOS interpolates the rect itself (`HeroFlightStore.rects`), so it can share
 * only half the geometry. Compose's shared-element API always animates
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
    // rendered outside NativeUIContent, or a navigation that isn't a morph).
    if (ref.isEmpty() || mode == "none") return this

    val spec = node.boundsSpec()

    // Text keeps its own layout and is scaled to the moving bounds. The
    // default re-measures content at every in-between size, which re-wraps a
    // title word by word as its box grows ("Plu / gin / s").
    val resize = if (node.type == "text") {
        SharedTransitionScope.ResizeMode.scaleToBounds()
    } else {
        SharedTransitionScope.ResizeMode.RemeasureToBounds
    }

    // Root scopes: the full-screen swap in NativeUIContent.
    val rootShared = LocalSharedTransitionScope.current
    val rootVisibility = LocalAnimatedVisibilityScope.current
    // Chrome scopes: a push or pop inside native chrome (ChromeMorphPane).
    val chrome = LocalChromeMorphScopes.current

    // A ref inside chrome can carry two registrations, one per scope, each
    // with a visibility scope that never changes under it. Compose takes a
    // flight's start bounds from the outgoing element's registration, so
    // moving one registration between scopes as a transition starts loses
    // them and the element just appears at its destination.
    //
    // While the renderer's `morphing` holds, the chain is built so that it
    // only changes at the end, because Compose matches modifier nodes by
    // position and class, and all three registrations are the same class.
    // The front changes twice, outside that window. When `morphing` turns
    // true, the inert registration joins at the front in the same
    // composition as the chrome registration, and the root registration's
    // node passes to it and a new one is made for the root. That's safe
    // because the root transition is idle while a chrome morph starts. When
    // `morphing` turns false (a later level change that doesn't morph, even
    // one that interrupts a running morph), the inert registration leaves
    // the front and the root takes its node, while that flight is being
    // dropped anyway.
    //
    // 1. An inert chrome registration, present while `morphing` holds: from
    //    a level change that morphs until the next one that doesn't, a tab
    //    switch or the first screen (see ChromeMorphTracker). It has a key
    //    private to the pane, so it never matches, and no enter or exit
    //    animation. In testing, two chrome registrations only matched when
    //    another shared-bounds modifier sat in front of the live one on the
    //    same node. On a bare node they often never matched (stack pops
    //    where PHP had restaged before the pop, so no root registration was
    //    there). A plain pass-through approach modifier did not help. It is
    //    left off otherwise: provided on every pane, it made ref'd lazy
    //    lists and pagers crash while scrolling with "measure is called on a
    //    deactivated node" in the lookahead pass, and removing it from
    //    panes reached without a morph stops that (#753).
    // 2. The root registration, frozen while the pane is in the chrome scope
    //    (ChromeMorphPane) and otherwise coming and going with the staged
    //    transition, as it always has.
    // 3. The chrome registration, appended while this pane takes part in a
    //    chrome morph.
    var modifier = this
    val inertScopes = LocalInertChromeScopes.current
    if (inertScopes != null) {
        modifier = with(inertScopes.sharedScope) {
            modifier.sharedBounds(
                sharedContentState = rememberSharedContentState(key = InertChromeKey(ref, inertScopes.paneScope)),
                animatedVisibilityScope = inertScopes.paneScope,
                enter = EnterTransition.None,
                exit = ExitTransition.None,
            )
        }
    }
    if (rootShared != null && rootVisibility != null) {
        modifier = with(rootShared) {
            modifier.sharedBounds(
                sharedContentState = rememberSharedContentState(key = ref),
                animatedVisibilityScope = rootVisibility,
                boundsTransform = BoundsTransform { _, _ -> spec },
                resizeMode = resize,
            )
        }
    }
    if (chrome != null) {
        modifier = with(chrome.sharedScope) {
            modifier.sharedBounds(
                sharedContentState = rememberSharedContentState(key = ref),
                animatedVisibilityScope = chrome.paneScope,
                boundsTransform = BoundsTransform { _, _ -> spec },
                resizeMode = resize,
            )
        }
    }
    return modifier
}

/** A key only its own pane holds, so the inert registration never matches. */
private data class InertChromeKey(val ref: String, val pane: AnimatedVisibilityScope)

/**
 * `morph-duration` (ms) and `morph-easing` for one element, falling back to a
 * 350ms ease-in-out that matches iOS's morph pace so an
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

/**
 * Decides, per push or pop, whether a native-chrome renderer
 * (`native_root_stack`, `native_root_tabs`) runs its level change as a morph.
 *
 * Inside chrome the root `NativeUIContent` swap never runs (the screenKey
 * bump is suppressed for chrome continuations), so the chrome renderer's own
 * `AnimatedContent` drives the shared elements. Three things make that
 * different from the full-screen case:
 *
 * - `pendingTransition` is never cleared. A level change only morphs when
 *   PHP staged `morph` for the navigation that caused it, which the
 *   renderer passes as [NavStamp]: the serial and transition recorded with
 *   each navigation publish. A serial already consumed by an earlier level
 *   change is stale, so a later redirect or local pop doesn't pick it up.
 * - PHP stages `slide_from_left` for every `back()` that doesn't name a
 *   transition. It has no memory of how the screen was entered, so the
 *   tracker remembers which levels arrived by morph and morphs them back
 *   out. That memory is kept per key, and tabs keys carry the tab, so it
 *   survives switching to another tab and back.
 * - The decision is taken once when the level changes and held until the
 *   next change, so a morph can't lose its scope halfway through and leave
 *   elements stranded.
 *
 * Call [settle] from composition with the renderer's target key on every
 * pass; it only does work when the key changes.
 */
internal class ChromeMorphTracker {
    data class NavStamp(val serial: Long, val transition: String?)

    private var shownKey: String? = null
    private var consumedSerial = Long.MIN_VALUE
    private val depths = HashMap<String, Int>()
    private val enteredByMorph = HashSet<String>()
    private var morphing = false

    /**
     * Returns true when the move to [key] should morph.
     *
     * [canMorph] rules out moves that aren't pushes or pops within one
     * stack (a tab switch). When [honourStagedPop] is true, a pop only
     * morphs back if PHP staged its default back transition or `morph`
     * itself, so an explicit `back(transition: Fade)` is still a fade. The
     * stack renderer passes false because it pops locally before PHP has
     * staged anything, and it ignores staged pop transitions anyway.
     */
    fun settle(
        key: String,
        depth: Int,
        nav: NavStamp,
        canMorph: (from: String) -> Boolean,
        honourStagedPop: Boolean,
    ): Boolean {
        val from = shownKey
        if (from == key) return morphing
        shownKey = key
        val staged = if (nav.serial != consumedSerial) nav.transition else null
        consumedSerial = nav.serial
        val fromDepth = from?.let { depths[it] } ?: depth
        depths[key] = depth
        if (depths.size > 32) depths.keys.retainAll(setOf(key) + enteredByMorph)

        if (from == null || !canMorph(from)) {
            // A tab switch, or the first screen. Leave the memory alone: the
            // level shown now may have been entered by morph before the user
            // switched away, and back from it should still morph.
            morphing = false
            return false
        }

        val popping = depth < fromDepth
        morphing = if (popping) {
            val stagedAllows = !honourStagedPop || staged == "slide_from_left"
            staged == "morph" || (from in enteredByMorph && stagedAllows)
        } else {
            staged == "morph"
        }
        if (popping) {
            enteredByMorph.remove(from)
        } else if (morphing) {
            enteredByMorph.add(key)
        } else {
            enteredByMorph.remove(key)
        }
        return morphing
    }
}

/**
 * The key a native-chrome renderer's `AnimatedContent` should target.
 *
 * A morphing change holds the old key for two frames so the outgoing pane
 * can join the chrome scope and be laid out there before the transition
 * starts; Compose takes the flight's start bounds from that layout. Any
 * other change goes through at once, as before.
 *
 * Arming from the staged `pendingTransition` before the tree lands is not
 * enough on its own: both come from the PHP thread, and whether a frame
 * runs between them is a race.
 */
@Composable
internal fun rememberChromeTransitionKey(target: String, morphing: Boolean): String {
    var shown by remember { mutableStateOf(target) }
    LaunchedEffect(target, morphing) {
        if (morphing && shown != target) {
            withFrameNanos { }
            withFrameNanos { }
        }
        shown = target
    }
    return if (morphing) shown else target
}

/**
 * Publishes the chrome shared-element scopes to one pane of a native-chrome
 * renderer's `AnimatedContent`. While the pane stays out of a chrome morph
 * the root scopes pass through untouched, so a full-screen morph into or out
 * of this chrome behaves as it did before chrome morphs existed. (While
 * [morphing] holds, each ref'd node in chrome also carries an inert chrome
 * registration; see `Modifier.heroMorph`.)
 *
 * The pane joins the chrome scope only while it takes part in a morphing
 * level change: while that change is held so the outgoing pane is laid out
 * in the scope first ([held], see [rememberChromeTransitionKey]), and while
 * it runs. It always joins with its own visibility scope. A tab switch or a
 * plain slide never sends refs travelling.
 */
@OptIn(ExperimentalSharedTransitionApi::class)
@Composable
internal fun ChromeMorphPane(
    sharedScope: SharedTransitionScope,
    paneScope: AnimatedVisibilityScope,
    morphing: Boolean,
    held: Boolean,
    content: @Composable () -> Unit,
) {
    val paneMoving = paneScope.transition.let { it.isRunning || it.currentState != it.targetState }
    val joins = morphing && (held || paneMoving)

    // While the chrome registration is on the chain, the root registration
    // in front of it must not change, or Compose hands the root's modifier
    // node to the chrome registration (same class, same position) and the
    // two never match. PHP restages the transition around a pop (the stack
    // pops locally, the republish stages `slide_from_left`), often in the
    // very composition the pane joins, so the root is frozen at what was on
    // the chain in the previous composition, not at what is inherited now.
    // On the way out, the chrome registration leaves first and the root
    // catches up one composition later.
    val inheritedRoot = LocalSharedTransitionScope.current
    val rootHold = remember { RootScopeHold() }
    var chromeOnChain by remember { mutableStateOf(false) }
    val frozen = joins || chromeOnChain
    if (frozen && !rootHold.frozen) rootHold.scope = rootHold.lastProvided
    rootHold.frozen = frozen
    val root = if (frozen) rootHold.scope else inheritedRoot
    rootHold.lastProvided = root
    SideEffect { if (chromeOnChain != joins) chromeOnChain = joins }

    val scopes = remember(sharedScope, paneScope) { ChromeMorphScopes(sharedScope, paneScope) }
    CompositionLocalProvider(
        LocalInertChromeScopes provides if (morphing) scopes else null,
        LocalSharedTransitionScope provides root,
        LocalChromeMorphScopes provides if (joins) scopes else null,
        content = content,
    )
}

@OptIn(ExperimentalSharedTransitionApi::class)
private class RootScopeHold {
    var frozen = false
    var scope: SharedTransitionScope? = null
    var lastProvided: SharedTransitionScope? = null
}
