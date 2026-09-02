package com.nativephp.mobile.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/** App-local appearance override shared by Material chrome and native nodes. */
object NativeAppearanceState {
    var mode: String? by mutableStateOf(null)

    @Composable
    fun isDark(): Boolean = resolve(isSystemInDarkTheme())

    fun resolve(systemIsDark: Boolean): Boolean = when (mode) {
        "light" -> false
        "dark" -> true
        else -> systemIsDark
    }
}

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
 *
 * Every slot is snapshot state, because registration LOSES THE RACE against the
 * first composition: `MainActivity` calls `setContent` from `onCreate`, while
 * the plugin init functions run from `registerBridgeFunctions` — deferred to a
 * background thread after the first frame to keep the boot off the TTID path.
 * As plain `var`s these would be read as null on that first pass and never
 * re-read (the `setContent` scope only recomposes when the system appearance
 * flips), leaving the app on Material's baseline palette — a pinkish neutral on
 * the top bar — for the rest of the process.
 */
object NativeUIThemeProvider {

    /** Set by a UI plugin to map `isDark` → a Material3 [ColorScheme]. */
    var colorSchemeFor: (@Composable (isDark: Boolean) -> ColorScheme)? by
        mutableStateOf<(@Composable (isDark: Boolean) -> ColorScheme)?>(null)

    /**
     * Set by a UI plugin to supply the app's Material3 [Typography] — e.g.
     * native-ui applies its theme's app-wide default font family across every
     * text style. Null (or a null return) keeps Material defaults, so core
     * chrome (top bars, tab labels, dropdowns) renders unchanged without a
     * UI plugin.
     */
    var typographyFor: (@Composable () -> Typography?)? by
        mutableStateOf<(@Composable () -> Typography?)?>(null)

    /**
     * Set by a UI plugin to resolve a font token (a bundled font file's
     * basename, e.g. "Inter-Bold") to a [FontFamily]. Lets core chrome honor
     * per-layout / per-bar `font_name` props without core knowing how fonts
     * are stored. Null (or a null return) means "no such font" — callers
     * fall back to the ambient typography.
     */
    var fontFamilyResolver: ((String) -> androidx.compose.ui.text.font.FontFamily?)? by
        mutableStateOf<((String) -> androidx.compose.ui.text.font.FontFamily?)?>(null)

    /** Resolve a chrome font token via the plugin, or null. */
    fun resolveChromeFontFamily(name: String): androidx.compose.ui.text.font.FontFamily? =
        if (name.isEmpty()) null else fontFamilyResolver?.invoke(name)

    /** The active color scheme: the plugin provider's, or a Material default. */
    @Composable
    fun resolve(isDark: Boolean): ColorScheme =
        colorSchemeFor?.invoke(isDark)
            ?: if (isDark) darkColorScheme() else lightColorScheme()

    /** The active typography: the plugin provider's, or Material defaults. */
    @Composable
    fun resolveTypography(): Typography =
        typographyFor?.invoke() ?: Typography()
}
