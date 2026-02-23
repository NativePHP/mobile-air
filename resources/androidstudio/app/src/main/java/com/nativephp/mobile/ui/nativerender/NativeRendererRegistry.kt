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
        // All other components provided by compose-ui plugin
    }
}
