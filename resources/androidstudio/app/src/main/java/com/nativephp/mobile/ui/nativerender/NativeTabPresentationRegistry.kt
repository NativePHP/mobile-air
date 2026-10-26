package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.Composable
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.ConcurrentHashMap

enum class NativeTabRole { NAVIGATION, SEARCH, ACTION }

internal fun nativeTabPresentationIdentifier(sourceIdentifier: String, index: Int): String =
    "$sourceIdentifier#$index"

data class NativeTabDescriptor(
    val id: String,
    val label: String,
    val accessibilityLabel: String,
    val icon: String,
    val badge: String?,
    val showsNews: Boolean,
    val role: NativeTabRole,
)

class NativeTabPresentationConfiguration private constructor(
    private val values: Map<String, Any?>,
) {
    fun string(key: String): String? = values[key] as? String
    fun boolean(key: String): Boolean? = values[key] as? Boolean
    fun number(key: String): Double? = (values[key] as? Number)?.toDouble()
    fun strings(key: String): List<String>? = (values[key] as? List<*>)?.mapNotNull { it as? String }
        ?.takeIf { it.size == (values[key] as List<*>).size }
    @Suppress("UNCHECKED_CAST")
    fun objectValue(key: String): Map<String, Any?>? = values[key] as? Map<String, Any?>

    companion object {
        fun parse(json: String): NativeTabPresentationConfiguration? {
            if (json.toByteArray(Charsets.UTF_8).size > 16_384) return null
            return try {
                NativeTabPresentationConfiguration(JSONObject(json).toMap(depth = 1))
            } catch (_: Exception) {
                null
            }
        }

        internal fun from(values: Map<String, Any?>) = NativeTabPresentationConfiguration(values)
    }
}

private fun JSONObject.toMap(depth: Int): Map<String, Any?> {
    require(depth <= 8) { "Tab presentation configuration exceeds the maximum depth." }
    return keys().asSequence().associateWith { key ->
        when (val value = get(key)) {
            JSONObject.NULL -> null
            is JSONObject -> value.toMap(depth + 1)
            is JSONArray -> value.toList(depth + 1)
            else -> value
        }
    }
}

private fun JSONArray.toList(depth: Int): List<Any?> {
    require(depth <= 8) { "Tab presentation configuration exceeds the maximum depth." }
    return (0 until length()).map { index ->
        when (val value = get(index)) {
            JSONObject.NULL -> null
            is JSONObject -> value.toMap(depth + 1)
            is JSONArray -> value.toList(depth + 1)
            else -> value
        }
    }
}

class NativeTabPresentationContext(
    val tabs: List<NativeTabDescriptor>,
    val selectedTabIdentifier: String,
    val configuration: NativeTabPresentationConfiguration,
    val isHidden: Boolean,
    private val activation: (String) -> Unit,
) {
    fun activate(tabIdentifier: String) {
        if (tabs.any { it.id == tabIdentifier }) activation(tabIdentifier)
    }
}

typealias NativeTabPresenter = @Composable (NativeTabPresentationContext) -> Unit

object NativeTabPresentationRegistry {
    private val presenters = ConcurrentHashMap<String, NativeTabPresenter>()

    /** Registers a presenter. The first registration for an identifier wins. */
    fun register(identifier: String, presenter: NativeTabPresenter): Boolean {
        val registered = presenters.putIfAbsent(identifier, presenter) == null
        if (!registered) {
            println("[NativePHP] Duplicate tab presenter '$identifier' ignored; first registration wins.")
        }
        return registered
    }

    internal fun presenter(identifier: String): NativeTabPresenter? = presenters[identifier]

    internal fun resolve(
        identifier: String,
        configuration: NativeTabPresentationConfiguration?,
    ): NativeTabPresenter? {
        if (identifier.isEmpty() || identifier == "system" || configuration == null) return null
        return presenter(identifier)
    }

    internal fun resetForTesting() = presenters.clear()
}
