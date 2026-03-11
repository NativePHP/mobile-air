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
 *
 * Container types (column, row, stack, pressable, canvas) are NOT registered —
 * RenderNode handles them generically via Yoga absolute positioning.
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
        // Navigation chrome
        register("top_bar", NodeRenderer { node, modifier -> RenderTopBar(node, modifier) })
        register("top_bar_action", NodeRenderer { node, modifier -> RenderTopBarAction(node, modifier) })
        register("bottom_nav", NodeRenderer { node, modifier -> RenderBottomNav(node, modifier) })
        register("bottom_nav_item", NodeRenderer { node, modifier -> RenderBottomNavItem(node, modifier) })
        register("side_nav", NodeRenderer { node, modifier -> RenderSideNav(node, modifier) })
        register("side_nav_item", NodeRenderer { node, modifier -> RenderSideNavItem(node, modifier) })
        register("side_nav_group", NodeRenderer { node, modifier -> RenderSideNavGroup(node, modifier) })
        register("side_nav_header", NodeRenderer { node, modifier -> RenderSideNavHeader(node, modifier) })

        // Core visual primitives
        register("text", NodeRenderer { node, modifier -> RenderText(node, modifier) })
        register("image", NodeRenderer { node, modifier -> RenderImage(node, modifier) })
        register("spacer", NodeRenderer { node, modifier -> RenderSpacer(node, modifier) })
        register("divider", NodeRenderer { node, modifier -> RenderDivider(node, modifier) })
        register("rect", NodeRenderer { node, modifier -> RenderRect(node, modifier) })
        register("circle", NodeRenderer { node, modifier -> RenderCircle(node, modifier) })
        register("line", NodeRenderer { node, modifier -> RenderLine(node, modifier) })

        // Content
        register("icon", NodeRenderer { node, modifier -> RenderIcon(node, modifier) })

        // Core interactive components
        register("button", NodeRenderer { node, modifier -> RenderButton(node, modifier) })
        register("text_input", NodeRenderer { node, modifier -> RenderTextInput(node, modifier) })
        register("toggle", NodeRenderer { node, modifier -> RenderToggle(node, modifier) })
        register("activity_indicator", NodeRenderer { node, modifier -> RenderActivityIndicator(node, modifier) })

        // Special containers (need custom rendering, not just absolute positioning)
        register("scroll_view", NodeRenderer { node, modifier -> RenderScrollView(node, modifier) })
        register("bottom_sheet", NodeRenderer { node, modifier -> RenderBottomSheet(node, modifier) })
    }
}
