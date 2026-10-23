package com.nativephp.mobile.bridge

import java.util.concurrent.locks.ReentrantLock
import kotlin.concurrent.withLock

/**
 * Runs [prepare] under [lock] — unless [isPersistentRuntime] reports a live
 * persistent PHP runtime once the lock is HELD. Returns null (and calls
 * [onAlreadyPersistent]) when the work was made unnecessary.
 *
 * Why the check lives in here and not (only) in the callers: the persistent
 * boot path takes this same lock and only flips the process-global runtime
 * flags while holding it. An initializer whose earlier isPersistentMode()
 * read said false can park on the lock while another initializer extracts
 * the bundle, runs the base artisan commands and boots the persistent
 * runtime — and on waking up it must not redo that work: in DEBUG the
 * extractor ALWAYS re-extracts, deleting and re-unzipping the laravel/ tree
 * (vendor/ included) under the interpreter that just booted, which breaks
 * the first PHP includes even though every file exists in the APK.
 *
 * Deliberately pure — lock and flag source come in as parameters so the
 * two-thread interleaving is exercised by a plain JVM unit test with no
 * Android context and no native library.
 */
internal fun <T> runUnderLockUnlessPersistent(
    lock: ReentrantLock,
    isPersistentRuntime: () -> Boolean,
    onAlreadyPersistent: () -> Unit,
    prepare: () -> T,
): T? = lock.withLock {
    if (isPersistentRuntime()) {
        onAlreadyPersistent()
        null
    } else {
        prepare()
    }
}
