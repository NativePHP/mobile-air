package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

typealias NativeNodeDecorator = @Composable (NativeUINode, Modifier) -> Modifier

/** Ordered, opt-in modifier decorators supplied by native plugins. */
object NativeNodeDecoratorRegistry {
    private val decorators = linkedMapOf<String, NativeNodeDecorator>()
    @Volatile private var snapshot: List<NativeNodeDecorator> = emptyList()

    @Synchronized
    fun register(name: String, decorator: NativeNodeDecorator) {
        decorators[name] = decorator
        snapshot = decorators.values.toList()
    }

    @Synchronized
    fun unregister(name: String) {
        decorators.remove(name)
        snapshot = decorators.values.toList()
    }

    fun isEmpty(): Boolean = snapshot.isEmpty()

    @Composable
    fun apply(node: NativeUINode, modifier: Modifier): Modifier {
        var decorated = modifier
        for (decorator in snapshot) decorated = decorator(node, decorated)
        return decorated
    }
}
