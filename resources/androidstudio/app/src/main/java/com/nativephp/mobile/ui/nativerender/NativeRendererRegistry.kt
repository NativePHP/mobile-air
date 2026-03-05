package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import java.util.concurrent.locks.ReentrantReadWriteLock
import kotlin.concurrent.read
import kotlin.concurrent.write

/**
 * Composable renderer function type for a single node.
 */
fun interface NodeRenderer {
    @Composable
    fun Render(node: NativeUINode, modifier: Modifier)
}

/**
 * Thread-safe registry mapping type strings to renderers.
 * Follows the same pattern as BridgeFunctionRegistry.
 *
 * Built-in types are registered at app startup via registerBuiltins().
 * Third-party plugins can register additional renderers at runtime.
 */
object NativeRendererRegistry {

    private val lock = ReentrantReadWriteLock()
    private val renderers = mutableMapOf<String, NodeRenderer>()

    fun register(type: String, renderer: NodeRenderer) {
        lock.write {
            renderers[type] = renderer
        }
    }

    fun get(type: String): NodeRenderer? {
        return lock.read {
            renderers[type]
        }
    }

    fun registerBuiltins() {
        register("column", NodeRenderer { node, modifier -> RenderColumn(node, modifier) })
        register("row", NodeRenderer { node, modifier -> RenderRow(node, modifier) })
        register("stack", NodeRenderer { node, modifier -> RenderStack(node, modifier) })
        register("scroll_view", NodeRenderer { node, modifier -> RenderScrollView(node, modifier) })

        // Navigation chrome
        register("top_bar", NodeRenderer { node, modifier -> RenderTopBar(node, modifier) })
        register("top_bar_action", NodeRenderer { node, modifier -> RenderTopBarAction(node, modifier) })
        register("bottom_nav", NodeRenderer { node, modifier -> RenderBottomNav(node, modifier) })
        register("bottom_nav_item", NodeRenderer { node, modifier -> RenderBottomNavItem(node, modifier) })
        register("side_nav", NodeRenderer { node, modifier -> RenderSideNav(node, modifier) })
        register("side_nav_item", NodeRenderer { node, modifier -> RenderSideNavItem(node, modifier) })
        register("side_nav_group", NodeRenderer { node, modifier -> RenderSideNavGroup(node, modifier) })
        register("side_nav_header", NodeRenderer { node, modifier -> RenderSideNavHeader(node, modifier) })
        register("fab", NodeRenderer { node, modifier -> RenderFab(node, modifier) })
        register("horizontal_divider", NodeRenderer { node, modifier -> RenderHorizontalDivider(node, modifier) })

        // All other components provided by compose-ui plugin
    }
}
