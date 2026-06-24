package com.nativephp.mobile.ui

import androidx.compose.material3.ColorScheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable

/**
 * Seam that lets a UI plugin supply the app's Material3 [ColorScheme] without
 * core depending on the plugin. A plugin registers [colorSchemeFor] from its
 * init function (e.g. native-ui maps its PHP-driven theme tokens); when nothing
 * is registered — no UI plugin installed — core falls back to Material defaults.
 *
 * This is what lets core build and run standalone: `MainActivity` themes itself
 * through this seam instead of referencing a plugin's theme store directly.
 *
 * The provider is invoked during composition, so a provider that reads Compose
 * state (PHP `Theme::merge` updates flow in as snapshot state) stays reactive
 * across recomposition.
 */
object NativeUIThemeProvider {

    /** Set by a UI plugin to map `isDark` → a Material3 [ColorScheme]. */
    var colorSchemeFor: (@Composable (isDark: Boolean) -> ColorScheme)? = null

    /** The active color scheme: the plugin provider's, or a Material default. */
    @Composable
    fun resolve(isDark: Boolean): ColorScheme =
        colorSchemeFor?.invoke(isDark)
            ?: if (isDark) darkColorScheme() else lightColorScheme()
}
