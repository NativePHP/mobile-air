package com.nativephp.mobile.ui

import android.content.Context
import android.os.Build
import android.os.PowerManager
import android.util.Log
import com.nativephp.mobile.bridge.functions.DeviceFunctions
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONObject

/**
 * App-wide thermal-state watcher.
 *
 * Android 10+ (API 29) registers [PowerManager.OnThermalStatusChangedListener]
 * and forwards real changes to PHP as
 * `Native\Mobile\Events\Device\ThermalStateChanged`. The OS calls the
 * listener once on register with the current status — that seed callback is
 * skipped so launch doesn't look like a change (same guard as appearance).
 *
 * [syncIfChanged] is the appearance-style resume nudge: re-read the OS when
 * the activity comes back and emit only if the value actually drifted while
 * we were backgrounded or frozen. [lastState] survives [stop] so an
 * activity recreate (persistent PHP still alive) can detect the same drift.
 *
 * Android 8–9 has no thermal API: [start] is a no-op and PHP's
 * `Device::thermalState()` stays `Normal`.
 *
 * Register in `Activity.onCreate`, sync in `onResume`, unregister in `onDestroy`.
 */
object ThermalStateMonitor {
    private const val TAG = "ThermalStateMonitor"
    private const val EVENT = "Native\\Mobile\\Events\\Device\\ThermalStateChanged"

    private var listener: PowerManager.OnThermalStatusChangedListener? = null
    private var lastState: String? = null

    fun start(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            return
        }
        if (listener != null) {
            return
        }

        val appContext = context.applicationContext
        val pm = appContext.getSystemService(Context.POWER_SERVICE) as? PowerManager ?: return

        val current = DeviceFunctions.currentThermalState(appContext)
        emitIfChanged(current)

        val thermalListener = PowerManager.OnThermalStatusChangedListener { status ->
            emitIfChanged(DeviceFunctions.normalizeThermalStatus(status))
        }

        listener = thermalListener
        pm.addThermalStatusListener(appContext.mainExecutor, thermalListener)
    }

    /**
     * Re-read the OS thermal status. Emits only when it differs from the last
     * value we told PHP — the resume nudge, matching appearance's
     * onConfigurationChanged path.
     */
    fun syncIfChanged(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            return
        }
        emitIfChanged(DeviceFunctions.currentThermalState(context.applicationContext))
    }

    fun stop(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            return
        }
        val thermalListener = listener ?: return
        val pm = context.applicationContext.getSystemService(Context.POWER_SERVICE) as? PowerManager
        pm?.removeThermalStatusListener(thermalListener)
        listener = null
        // Keep lastState so a later start()/syncIfChanged() can emit if the
        // OS drifted while this activity was gone (persistent PHP cache).
    }

    private fun emitIfChanged(state: String) {
        val previous = lastState
        if (previous == null || state == previous) {
            lastState = state
            return
        }
        lastState = state
        Log.d(TAG, "Thermal state $previous → $state")
        NativeElementBridge.sendNativeEvent(
            EVENT,
            JSONObject()
                .put("state", state)
                .put("previous", previous)
                .toString()
        )
    }
}
