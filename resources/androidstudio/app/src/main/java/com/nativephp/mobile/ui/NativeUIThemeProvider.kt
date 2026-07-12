package com.nativephp.mobile.ui

import androidx.compose.material3.ColorScheme
import androidx.compose.material3.Typography
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

    /**
     * Set by a UI plugin to supply the app's Material3 [Typography] — e.g.
     * native-ui applies its theme's app-wide default font family across every
     * text style. Null (or a null return) keeps Material defaults, so core
     * chrome (top bars, tab labels, dropdowns) renders unchanged without a
     * UI plugin.
     */
    var typographyFor: (@Composable () -> Typography?)? = null

    /**
     * Set by a UI plugin to resolve a font token (a bundled font file's
     * basename, e.g. "Inter-Bold") to a [FontFamily]. Lets core chrome honor
     * per-layout / per-bar `font_name` props without core knowing how fonts
     * are stored. Null (or a null return) means "no such font" — callers
     * fall back to the ambient typography.
     */
    var fontFamilyResolver: ((String) -> androidx.compose.ui.text.font.FontFamily?)? = null

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
