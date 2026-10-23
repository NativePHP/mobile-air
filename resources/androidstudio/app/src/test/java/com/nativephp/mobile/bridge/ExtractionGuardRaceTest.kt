package com.nativephp.mobile.bridge

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicReference
import java.util.concurrent.locks.ReentrantLock

/**
 * The device startup race the guard closes: a queued initializer reads
 * isPersistentMode() == false, parks on the extraction lock while the
 * booting initializer extracts, runs the base artisan commands and boots
 * the persistent runtime — the process-global flags flip while THAT
 * initializer still holds the lock — then wakes up with the lock and must
 * NOT re-extract. In DEBUG the extractor always re-extracts, deleting and
 * re-unzipping vendor/ under the live interpreter, which is how a PHP
 * include fails on a file that exists in the APK and is rewritten
 * milliseconds later.
 *
 * Drives the PRODUCTION guard with that exact interleaving: the second
 * initializer's fast-path read happens before the boot, and the flag flips
 * only while the first initializer holds the lock.
 */
class ExtractionGuardRaceTest {
    @Test
    fun secondInitializerSkipsPrepareWhenRuntimeBootsWhileItWaitsForTheLock() {
        val lock = ReentrantLock()
        val persistentRuntime = AtomicBoolean(false)
        val fastPathReadBeforeLock = AtomicBoolean(true)
        val onAlreadyPersistent = AtomicBoolean(false)
        val prepareRan = AtomicBoolean(false)
        val secondInitializerDone = CountDownLatch(1)
        val failure = AtomicReference<Throwable?>(null)

        // The booting initializer: holds the extraction lock across its
        // extraction + artisan + persistent boot, exactly like
        // bootPersistentRuntime() holding LaravelEnvironment.extractionLock.
        lock.lock()

        val secondInitializer = Thread {
            try {
                // Fast-path check outside the lock — races the boot, must read false.
                fastPathReadBeforeLock.set(persistentRuntime.get())

                val result = runUnderLockUnlessPersistent(
                    lock,
                    { persistentRuntime.get() },
                    { onAlreadyPersistent.set(true) },
                ) {
                    prepareRan.set(true)
                    "extracted"
                }

                assertNull("guard must skip the work when the runtime booted under the lock", result)
            } catch (error: Throwable) {
                failure.set(error)
            } finally {
                secondInitializerDone.countDown()
            }
        }
        secondInitializer.start()

        // Wait until the second initializer is parked on the lock, as it is
        // while the booting initializer finishes extraction and boot.
        try {
            awaitParkedOnLock(lock, secondInitializer)

            // bootPersistentRuntime() completes while still holding the lock:
            // process-global flags flip BEFORE the lock is released.
            persistentRuntime.set(true)
        } finally {
            lock.unlock()
        }

        assertTrue("second initializer never finished", secondInitializerDone.await(5, TimeUnit.SECONDS))
        failure.get()?.let { throw AssertionError("second initializer failed", it) }
        assertFalse("fast-path check must have raced the boot and read false", fastPathReadBeforeLock.get())
        assertFalse("prepare must not run under a live persistent runtime", prepareRan.get())
        assertTrue("skip callback must fire", onAlreadyPersistent.get())
    }

    @Test
    fun prepareRunsAndReturnsItsValueOnAColdStart() {
        val lock = ReentrantLock()
        val persistentRuntime = AtomicBoolean(false)

        val result = runUnderLockUnlessPersistent(
            lock,
            { persistentRuntime.get() },
            { error("nothing to skip on a cold start") },
        ) { "extracted" }

        assertEquals("extracted", result)
    }

    /** Blocks until [thread] is parked waiting for [lock], or fails after 5s. */
    private fun awaitParkedOnLock(lock: ReentrantLock, thread: Thread) {
        val deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(5)
        while (System.nanoTime() < deadline) {
            if (lock.hasQueuedThread(thread) || thread.state == Thread.State.WAITING) return
            Thread.sleep(10)
        }
        throw AssertionError("second initializer never reached the extraction lock")
    }
}
