package com.nativephp.mobile.ui

import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import kotlin.math.sqrt

/**
 * Accelerometer-based device-shake detector.
 *
 * Computes total g-force from the accelerometer; when it crosses
 * [SHAKE_THRESHOLD_G] (rate-limited by [SHAKE_DEBOUNCE_MS] so one physical
 * shake fires once) it invokes [onShake]. Register it in `Activity.onResume`
 * and unregister in `onPause`.
 *
 * `onShake` forwards to PHP over the native-event channel as
 * `Native\Mobile\Events\Motion\ShakeDetected` (handled with
 * `#[On(ShakeDetected::class)]`) — no node, no binary wire-format change.
 */
class ShakeDetector(private val onShake: () -> Unit) : SensorEventListener {
    private var lastShakeMs = 0L

    override fun onSensorChanged(event: SensorEvent) {
        if (event.sensor.type != Sensor.TYPE_ACCELEROMETER) return

        val x = event.values[0]
        val y = event.values[1]
        val z = event.values[2]
        val gForce = sqrt((x * x + y * y + z * z).toDouble()) / SensorManager.GRAVITY_EARTH

        if (gForce > SHAKE_THRESHOLD_G) {
            val now = System.currentTimeMillis()
            if (now - lastShakeMs < SHAKE_DEBOUNCE_MS) return
            lastShakeMs = now
            onShake()
        }
    }

    override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {}

    companion object {
        private const val SHAKE_THRESHOLD_G = 2.7
        private const val SHAKE_DEBOUNCE_MS = 500L
    }
}
