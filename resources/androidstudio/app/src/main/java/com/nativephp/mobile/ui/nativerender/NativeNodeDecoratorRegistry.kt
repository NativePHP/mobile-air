package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import java.util.concurrent.locks.ReentrantReadWriteLock
import kotlin.concurrent.write

typealias NativeNodeDecorator = @Composable (NativeUINode, Modifier) -> Modifier

/** Ordered, opt-in modifier decorators supplied by native plugins. */
object NativeNodeDecoratorRegistry {
    private val lock = ReentrantReadWriteLock()
    private val decorators = linkedMapOf<String, NativeNodeDecorator>()
    @Volatile private var snapshot: List<NativeNodeDecorator> = emptyList()

    fun register(name: String, decorator: NativeNodeDecorator) {
        lock.write {
            decorators[name] = decorator
            snapshot = decorators.values.toList()
        }
    }

    fun unregister(name: String) {
        lock.write {
            decorators.remove(name)
            snapshot = decorators.values.toList()
        }
    }

    fun isEmpty(): Boolean = snapshot.isEmpty()

    @Composable
    fun apply(node: NativeUINode, modifier: Modifier): Modifier {
        var decorated = modifier
        for (decorator in snapshot) decorated = decorator(node, decorated)
        return decorated
    }
}
