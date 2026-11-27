package com.nativephp.mobile.ui.nativerender

import android.util.Log
import java.util.concurrent.locks.ReentrantReadWriteLock
import kotlin.concurrent.read
import kotlin.concurrent.write

/**
 * Thread-safe registry mapping type strings to Compose-based node renderers.
 * Core registers its chrome renderers via registerNativeChromeRenderers();
 * every other type (text, button, image, ...) is registered by UI plugins
 * via the generated registerPluginRenderers().
 *
 * Every registration records an owner: [CORE_OWNER], or the package name of
 * the plugin whose nativephp.json declared the component. A type belongs to
 * whoever registers it first. A registration from another owner is refused
 * and logged, so a plugin can't quietly replace core's chrome or another
 * plugin's renderer. Once startup registration is done ([lockRegistrations]),
 * new types are refused too. Refusals never crash the app; they only log.
 */
object NativeRendererRegistry {

    /** Owner for the renderers core registers itself. */
    const val CORE_OWNER = "core"

    /** Owner for registrations that don't name one (code generated before
     *  owners existed). They follow the same rules as everyone else. */
    const val UNKNOWN_OWNER = "unknown"

    private const val TAG = "NativePHP"

    private val lock = ReentrantReadWriteLock()
    private val renderers = mutableMapOf<String, NodeRenderer>()
    private val owners = mutableMapOf<String, String>()
    private var locked = false

    /** Logs a refusal. Swappable so JVM unit tests don't need android.util.Log. */
    internal var logError: (String) -> Unit = { message -> Log.e(TAG, message) }

    /** Kept for plugin code generated before owners existed. Registers under [UNKNOWN_OWNER]. */
    fun register(type: String, renderer: NodeRenderer) {
        register(type, UNKNOWN_OWNER, renderer)
    }

    /**
     * Registers [renderer] for [type] on behalf of [owner].
     *
     * Registering a type again under the same owner keeps the first renderer
     * and does nothing else. Returns false when the registration was refused:
     * another owner holds the type, or the registry is locked.
     */
    fun register(type: String, owner: String, renderer: NodeRenderer): Boolean {
        val refusal = lock.write {
            val current = owners[type]
            when {
                current == owner -> null
                current != null ->
                    "Refused the renderer for '$type' from $owner: it is already registered by $current."
                locked ->
                    "Refused the renderer for '$type' from $owner: renderers can only be registered during startup."
                else -> {
                    renderers[type] = renderer
                    owners[type] = owner
                    null
                }
            }
        }

        if (refusal != null) {
            logError(refusal)
            return false
        }
        return true
    }

    /**
     * Refuses every later registration of a new type. Called once core,
     * plugin init functions and generated plugin renderers have run.
     */
    fun lockRegistrations() {
        lock.write { locked = true }
    }

    /** Who registered [type]: [CORE_OWNER], a plugin package name, or null. */
    fun ownerOf(type: String): String? {
        return lock.read { owners[type] }
    }

    fun get(type: String): NodeRenderer? {
        return lock.read {
            renderers[type]
        }
    }

    /** For tests only: forget every registration and unlock. */
    internal fun resetForTesting() {
        lock.write {
            renderers.clear()
            owners.clear()
            locked = false
        }
    }
}
