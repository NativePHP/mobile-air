package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.Composable

/**
 * Registry for plugin-provided **root host wrappers** — chrome that needs to
 * wrap the *entire* rendered screen tree (side drawers, global overlays,
 * snackbar hosts, …) rather than render in place as a node.
 *
 * This is the native half of the framework's chrome seam. Core stays
 * chrome-agnostic: it never names "drawer" (or any specific chrome). A plugin
 * registers a host from its init function (which runs in `onCreate` before the
 * first composition — see `registerPluginBridgeFunctions()`), and core folds
 * every registered host around the root in [NativeUIContent].
 *
 * A host typically pairs with a **sentinel element** that a PHP chrome
 * contributor appends to the published tree (e.g. `native_drawer`). The host
 * declares the sentinel type it [consumes] so the root renderers keep that
 * marker out of the visible screen content; the host pulls the matching child
 * out of `root.children` itself and renders nothing when it's absent.
 *
 * Example (in a plugin's init function):
 * ```
 * NativeRootHostRegistry.register(
 *     "native-ui.drawer",
 *     consumes = "native_drawer",
 *     reservesNavigationBarSlot = true,
 * ) { root, content ->
 *     val node = root.children.firstOrNull { it.type == "native_drawer" }
 *     NativeLayoutDrawerHost(drawerNode = node, content = content)
 * }
 * ```
 */
object NativeRootHostRegistry {

    /** A host wraps the rendered tree: given the published root node (so it can
     *  find its own sentinel child) and the content rendered so far. */
    private class Entry(
        val name: String,
        val host: @Composable (root: NativeUINode, content: @Composable () -> Unit) -> Unit,
    )

    private val entries = mutableListOf<Entry>()
    private val consumedTypes = mutableSetOf<String>()
    private val navigationBarSlotTypes = mutableSetOf<String?>()

    /**
     * Register a root host. [consumes] is the sentinel element type this host
     * pulls out of the tree (if any), so the root renderers exclude it from
     * screen content. [reservesNavigationBarSlot] tells the root renderers to
     * leave room for navigation chrome drawn by the host. For hosts that
     * consume a sentinel, the slot is reserved only while that sentinel is
     * present in the current root. Hosts apply in registration order — the
     * first registered ends up innermost (closest to the content).
     */
    fun register(
        name: String,
        consumes: String? = null,
        reservesNavigationBarSlot: Boolean = false,
        host: @Composable (root: NativeUINode, content: @Composable () -> Unit) -> Unit,
    ) {
        if (consumes != null) consumedTypes.add(consumes)
        if (reservesNavigationBarSlot) navigationBarSlotTypes.add(consumes)
        entries.add(Entry(name, host))
    }

    /** Whether some registered host consumes this element type (so the root
     *  renderers should keep it out of the visible screen content). */
    fun consumes(type: String): Boolean = consumedTypes.contains(type)

    /**
     * Whether an active host draws chrome over the navigation-bar slot.
     * Sentinel-backed hosts are active only when their sentinel is present in
     * [root]; hosts without a sentinel are considered active globally.
     */
    fun reservesNavigationBarSlot(root: NativeUINode): Boolean = navigationBarSlotTypes.any { type ->
        type == null || root.children.any { it.type == type }
    }

    /**
     * Fold every registered host around [content]. A transparent pass-through
     * when no hosts are registered, so trees that use no plugin chrome pay
     * nothing.
     */
    @Composable
    fun Wrap(root: NativeUINode, content: @Composable () -> Unit) {
        WrapFrom(0, root, content)
    }

    @Composable
    private fun WrapFrom(index: Int, root: NativeUINode, content: @Composable () -> Unit) {
        if (index >= entries.size) {
            content()
            return
        }
        entries[index].host(root) {
            WrapFrom(index + 1, root, content)
        }
    }
}
