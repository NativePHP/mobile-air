package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.SaveableStateHolder
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.saveable.rememberSaveableStateHolder

/**
 * Saved view state per mounted screen, for the native chrome renderers.
 *
 * Their `AnimatedContent` composes only the screen on top, so a screen
 * pushed over is disposed and its `rememberSaveable` state (a
 * `native:list`'s scroll position) went with it, while PHP kept the
 * screen alive and resumed it on back (#298). Wrapping each screen in
 * [ScreenStateScope] parks that state in the returned holder under the
 * screen's token until the screen comes back.
 *
 * [tokens] is the frame's `screen_tokens`. Tokens that leave it (popped or
 * replaced screens) have their state removed. The ledger of tokens already
 * seen is saveable itself, so after a process restart the restored ledger
 * names the old tokens and they are all removed on the first frame, since
 * no new mount can reuse them. A frame without tokens changes nothing.
 *
 * Derived from the per-mount saveable panes in #588.
 */
@Composable
internal fun rememberScreenStateHolder(tokens: List<String>): SaveableStateHolder {
    val holder = rememberSaveableStateHolder()
    val ledger = rememberSaveable { mutableStateOf(ArrayList<String>()) }
    SideEffect {
        if (tokens.isNotEmpty()) {
            ScreenTokens.evicted(ledger.value, tokens).forEach { holder.removeState(it) }
            ledger.value = ArrayList(tokens)
        }
    }
    return holder
}

/**
 * Composes [content] in [token]'s saved-state scope, or plainly when the
 * frame carries no token (nothing is saved or restored then).
 */
@Composable
internal fun ScreenStateScope(holder: SaveableStateHolder, token: String?, content: @Composable () -> Unit) {
    if (token != null) {
        holder.SaveableStateProvider(token, content)
    } else {
        content()
    }
}
