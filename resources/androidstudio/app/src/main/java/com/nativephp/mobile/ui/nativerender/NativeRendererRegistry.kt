package com.nativephp.mobile.ui.nativerender

import java.util.concurrent.locks.ReentrantReadWriteLock
import kotlin.concurrent.read
import kotlin.concurrent.write

/**
 * Thread-safe registry mapping type strings to Compose-based node renderers.
 * All component renderers (including core types like text, button, image)
 * are registered here by the native-ui plugin via registerPluginRenderers().
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
}
