package com.nativephp.mobile.utils

import android.util.Log
import com.nativephp.mobile.BuildConfig

/**
 * Logging that costs nothing in release builds.
 *
 * d and i only log when BuildConfig.DEBUG is true. The message is a lambda, so in a
 * release build it is never built and R8 strips the call entirely. Use android.util.Log
 * directly (Log.e / Log.w) only for real failures a developer needs from a user's logcat,
 * and keep request bodies, cookies and other user data out of those lines.
 *
 * perf() is for boot and timing markers a perf harness counts. It also logs in release
 * when the device has `adb shell setprop debug.nativephp.perf_timing 1`.
 */
object NativeLog {
    inline fun d(tag: String, tr: Throwable? = null, message: () -> String) {
        if (BuildConfig.DEBUG) Log.d(tag, message(), tr)
    }

    inline fun i(tag: String, tr: Throwable? = null, message: () -> String) {
        if (BuildConfig.DEBUG) Log.i(tag, message(), tr)
    }

    inline fun perf(tag: String, message: () -> String) {
        // Log.println, because proguard-rules.pro strips Log.i from minified builds
        if (BuildConfig.DEBUG || perfTimingEnabled) Log.println(Log.INFO, tag, message())
    }

    /** Read once. Reflection because SystemProperties is not public SDK API. */
    val perfTimingEnabled: Boolean by lazy {
        try {
            val get = Class.forName("android.os.SystemProperties")
                .getMethod("get", String::class.java)
            get.invoke(null, "debug.nativephp.perf_timing") == "1"
        } catch (t: Throwable) {
            false
        }
    }
}
