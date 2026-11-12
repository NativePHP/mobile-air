package com.nativephp.mobile.ui

import android.app.Activity
import android.app.Application
import android.os.Bundle
import android.os.SystemClock
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONObject

/**
 * Tells PHP when the app goes to the background and when it comes back, as
 * `Native\Mobile\Events\App\AppBackgrounded` and `AppForegrounded`.
 *
 * The app is in the background once no activity is started. Counting started
 * activities, rather than watching MainActivity alone, keeps the camera,
 * browser and other activities the app opens from looking like a trip to the
 * background, and an activity recreated for a configuration change is not
 * one either. A cold launch fires neither event.
 *
 * Time away is measured on `SystemClock.elapsedRealtime()`, which keeps
 * counting while the device sleeps, so a timeout still holds after the phone
 * was locked.
 *
 * Start from MainActivity.onCreate; later calls do nothing. Callbacks run on
 * the main thread.
 */
object AppLifecycleMonitor : Application.ActivityLifecycleCallbacks {
    private const val BACKGROUNDED_EVENT = "Native\\Mobile\\Events\\App\\AppBackgrounded"
    private const val FOREGROUNDED_EVENT = "Native\\Mobile\\Events\\App\\AppForegrounded"

    private var registered = false
    private var startedActivities = 0
    private var backgroundedAt: Long? = null

    fun start(application: Application) {
        if (registered) {
            return
        }

        registered = true
        application.registerActivityLifecycleCallbacks(this)
    }

    override fun onActivityStarted(activity: Activity) {
        val returning = startedActivities == 0
        startedActivities++

        val since = backgroundedAt
        if (!returning || since == null) {
            return
        }

        backgroundedAt = null
        val secondsAway = maxOf(0L, SystemClock.elapsedRealtime() - since) / 1000

        NativeElementBridge.sendNativeEvent(
            FOREGROUNDED_EVENT,
            JSONObject().put("secondsAway", secondsAway).toString()
        )
    }

    override fun onActivityStopped(activity: Activity) {
        startedActivities = maxOf(0, startedActivities - 1)

        if (startedActivities > 0 || activity.isChangingConfigurations) {
            return
        }

        if (backgroundedAt == null) {
            backgroundedAt = SystemClock.elapsedRealtime()
        }

        NativeElementBridge.sendNativeEvent(BACKGROUNDED_EVENT, "{}")
    }

    override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) {}

    override fun onActivityResumed(activity: Activity) {}

    override fun onActivityPaused(activity: Activity) {}

    override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) {}

    override fun onActivityDestroyed(activity: Activity) {}
}
