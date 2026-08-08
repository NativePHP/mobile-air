package com.nativephp.mobile.ui.nativerender

import java.util.concurrent.atomic.AtomicInteger

/** Opt-in observation seam for the decoded native tree. */
object NativeTreeObserverRegistry {
    data class Publication(
        val id: Long,
        val tree: NativeUITree,
    )

    data class Subscription internal constructor(internal val id: Int)

    private val sequence = AtomicInteger(0)
    private val lock = Any()
    private val observers = linkedMapOf<Int, (Publication) -> Unit>()
    @Volatile private var latestPublication: Publication? = null
    @Volatile private var hasObservers = false

    fun observe(observer: (Publication) -> Unit): Subscription {
        val id = sequence.incrementAndGet()
        val replay = synchronized(lock) {
            observers[id] = observer
            hasObservers = true
            latestPublication
        }
        replay?.let { publication -> runCatching { observer(publication) } }
        return Subscription(id)
    }

    fun unsubscribe(subscription: Subscription) {
        synchronized(lock) {
            observers.remove(subscription.id)
            hasObservers = observers.isNotEmpty()
        }
    }

    internal fun publish(publication: Publication) {
        latestPublication = publication
        if (!hasObservers) return
        val current = synchronized(lock) { observers.values.toList() }
        current.forEach { observer -> runCatching { observer(publication) } }
    }
}
