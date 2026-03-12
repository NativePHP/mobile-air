package com.nativephp.mobile.ui.nativerender

import android.content.Context
import android.view.View
import java.util.concurrent.locks.ReentrantReadWriteLock
import kotlin.concurrent.read
import kotlin.concurrent.write

/**
 * Imperative view renderer interface — mirrors iOS NativeViewRenderer protocol.
 */
interface NativeViewRenderer {
    fun createView(context: Context, node: NativeUINode): View
    fun updateView(view: View, node: NativeUINode)
}

/**
 * Thread-safe registry mapping type strings to view renderers.
 *
 * Container types (column, row, stack, pressable, canvas) are NOT registered —
 * NativeUIViewRenderer handles them generically via Yoga absolute positioning.
 */
object NativeRendererRegistry {

    private val lock = ReentrantReadWriteLock()
    private val viewRenderers = mutableMapOf<String, NativeViewRenderer>()

    fun register(type: String, renderer: NativeViewRenderer) {
        lock.write {
            viewRenderers[type] = renderer
        }
    }

    fun getView(type: String): NativeViewRenderer? {
        return lock.read {
            viewRenderers[type]
        }
    }

    fun registerBuiltins() {
        // Core visual primitives
        register("text", TextViewRenderer())
        register("image", ImageViewRenderer())
        register("spacer", SpacerViewRenderer())
        register("divider", DividerViewRenderer())
        register("rect", RectViewRenderer())
        register("circle", CircleViewRenderer())
        register("line", LineViewRenderer())

        // Interactive components
        register("button", ButtonViewRenderer())
        register("text_input", TextInputViewRenderer())
        register("toggle", ToggleViewRenderer())
        register("icon", IconViewRenderer())
        register("activity_indicator", ActivityIndicatorViewRenderer())

        // Navigation chrome (empty renderers — handled at higher level)
        val empty = EmptyViewRenderer()
        register("top_bar", TopBarViewRenderer())
        register("top_bar_action", empty)
        register("bottom_nav", BottomNavViewRenderer())
        register("bottom_nav_item", empty)
        register("side_nav", SideNavViewRenderer())
        register("side_nav_item", empty)
        register("side_nav_group", empty)
        register("side_nav_header", empty)
    }
}
