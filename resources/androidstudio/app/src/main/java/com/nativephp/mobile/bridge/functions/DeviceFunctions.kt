package com.nativephp.mobile.bridge.functions

import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.os.BatteryManager
import android.os.Build
import android.os.Debug
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.provider.Settings
import android.webkit.WebView
import androidx.annotation.RequiresApi
import com.nativephp.mobile.bridge.BridgeFunction
import org.json.JSONObject

/**
 * Functions related to device hardware operations
 * Namespace: "Device.*"
 *
 * Core built-in (migrated from the `nativephp/mobile-device` plugin). Registered
 * directly in `BridgeFunctionRegistration.kt` alongside Edge/Perf/UI — no plugin
 * install required. iOS twin: `Bridge/Functions/DeviceFunctions.swift`.
 */
object DeviceFunctions {

    private var flashlightState = false

    /**
     * Vibrate the device
     * Parameters: none
     * Returns:
     *   - success: boolean - Whether vibration was triggered
     */
    @RequiresApi(Build.VERSION_CODES.O)
    class Vibrate(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return try {
                val vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    val manager = context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager
                    manager?.defaultVibrator
                } else {
                    @Suppress("DEPRECATION")
                    context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
                }

                if (vibrator != null) {
                    val effect = VibrationEffect.createOneShot(200, VibrationEffect.DEFAULT_AMPLITUDE)
                    vibrator.vibrate(effect)
                    mapOf("success" to true)
                } else {
                    mapOf("success" to false)
                }
            } catch (e: Exception) {
                mapOf("success" to false, "error" to (e.message ?: "Unknown error"))
            }
        }
    }

    /**
     * Toggle the device flashlight on/off
     * Parameters: none
     * Returns:
     *   - success: boolean - Whether flashlight was toggled
     *   - state: boolean - Current flashlight state (on = true, off = false)
     */
    class ToggleFlashlight(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return try {
                val cameraManager = context.getSystemService(Context.CAMERA_SERVICE) as CameraManager
                val cameraId = cameraManager.cameraIdList.firstOrNull {
                    cameraManager.getCameraCharacteristics(it)
                        .get(CameraCharacteristics.FLASH_INFO_AVAILABLE) == true
                }

                if (cameraId != null) {
                    flashlightState = !flashlightState
                    cameraManager.setTorchMode(cameraId, flashlightState)
                    mapOf("success" to true, "state" to flashlightState)
                } else {
                    mapOf("success" to false, "error" to "Flashlight not available")
                }
            } catch (e: Exception) {
                mapOf("success" to false, "error" to (e.message ?: "Unknown error"))
            }
        }
    }

    /**
     * Get the unique device ID
     * Parameters: none
     * Returns:
     *   - id: string - Unique device identifier (Android ID)
     */
    class GetId(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return try {
                val androidId = Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
                val deviceId = androidId ?: "unknown"
                mapOf("id" to deviceId)
            } catch (e: Exception) {
                mapOf("id" to "unknown")
            }
        }
    }

    /**
     * Get detailed device information
     * Parameters: none
     * Returns:
     *   - JSON string with device details (name, model, platform, osVersion, etc.)
     */
    class GetInfo(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return try {
                val deviceInfo = JSONObject().apply {
                    // Device name (Android 7.1+)
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N_MR1) {
                        try {
                            val deviceName = Settings.Global.getString(context.contentResolver, "device_name")
                                ?: Settings.Secure.getString(context.contentResolver, "bluetooth_name")
                                ?: "${Build.MANUFACTURER} ${Build.MODEL}"
                            put("name", deviceName)
                        } catch (e: Exception) {
                            put("name", "${Build.MANUFACTURER} ${Build.MODEL}")
                        }
                    } else {
                        put("name", "${Build.MANUFACTURER} ${Build.MODEL}")
                    }

                    // Model (device identifier)
                    put("model", Build.MODEL)

                    // Platform
                    put("platform", "android")

                    // Operating System
                    put("operatingSystem", "Android")

                    // OS Version
                    put("osVersion", Build.VERSION.RELEASE)

                    // Android SDK Version (specific to Android)
                    put("androidSDKVersion", Build.VERSION.SDK_INT)

                    // Manufacturer
                    put("manufacturer", Build.MANUFACTURER)

                    // Language (BCP 47 tag, e.g. "en-US")
                    put("language", java.util.Locale.getDefault().toLanguageTag())

                    // Virtual device detection
                    put("isVirtual", isEmulator())

                    // Memory usage
                    put("memUsed", getMemoryUsage())

                    // WebView version
                    put("webViewVersion", getWebViewVersion(context))
                }

                val result = deviceInfo.toString()
                mapOf("info" to result)
            } catch (e: Exception) {
                mapOf("info" to "{\"error\": \"${e.message}\"}")
            }
        }

        private fun isEmulator(): Boolean {
            return (Build.FINGERPRINT.startsWith("generic") ||
                    Build.FINGERPRINT.startsWith("unknown") ||
                    Build.FINGERPRINT.contains("test-keys") ||
                    Build.MODEL.contains("google_sdk") ||
                    Build.MODEL.contains("Emulator") ||
                    Build.MODEL.contains("Android SDK built for x86") ||
                    Build.MODEL.contains("sdk_gphone") ||
                    Build.MODEL.contains("sdk_gphone64") ||
                    Build.MANUFACTURER.contains("Genymotion") ||
                    Build.MANUFACTURER.equals("Google", ignoreCase = true) ||
                    Build.BRAND.startsWith("generic") && Build.DEVICE.startsWith("generic") ||
                    Build.DEVICE.contains("generic") ||
                    Build.DEVICE.contains("emulator") ||
                    Build.HARDWARE.contains("goldfish") ||
                    Build.HARDWARE.contains("ranchu") ||
                    "google_sdk" == Build.PRODUCT ||
                    Build.PRODUCT.contains("sdk") ||
                    Build.PRODUCT.contains("google_sdk") ||
                    Build.PRODUCT.contains("sdk_gphone") ||
                    Build.PRODUCT.contains("emulator"))
        }

        private fun getMemoryUsage(): Long {
            return try {
                val memInfo = Debug.MemoryInfo()
                Debug.getMemoryInfo(memInfo)
                // Return total private memory in bytes
                (memInfo.totalPrivateDirty * 1024).toLong()
            } catch (e: Exception) {
                -1L
            }
        }

        private fun getWebViewVersion(context: Context): String {
            return try {
                val packageInfo = context.packageManager.getPackageInfo("com.google.android.webview", 0)
                packageInfo.versionName ?: "unknown"
            } catch (e: Exception) {
                try {
                    // Fallback: try to get from WebView
                    val webView = WebView(context)
                    val userAgent = webView.settings.userAgentString
                    webView.destroy()

                    // Extract version from user agent
                    val chromeRegex = "Chrome/([\\d.]+)".toRegex()
                    chromeRegex.find(userAgent)?.groupValues?.get(1) ?: "unknown"
                } catch (e2: Exception) {
                    "unknown"
                }
            }
        }
    }

    /**
     * Get battery information
     * Parameters: none
     * Returns:
     *   - JSON string with battery level (0-1) and charging status
     */
    class GetBatteryInfo(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return try {
                val batteryIntentFilter = IntentFilter(Intent.ACTION_BATTERY_CHANGED)
                val batteryStatus = context.registerReceiver(null, batteryIntentFilter)

                val level = batteryStatus?.getIntExtra(BatteryManager.EXTRA_LEVEL, -1) ?: -1
                val scale = batteryStatus?.getIntExtra(BatteryManager.EXTRA_SCALE, -1) ?: -1

                // Battery level as a percentage (0 to 1) - exactly as specified
                val batteryLevel = if (level != -1 && scale != -1) {
                    level / scale.toFloat()
                } else -1f

                val status = batteryStatus?.getIntExtra(BatteryManager.EXTRA_STATUS, -1) ?: -1
                val isCharging = status == BatteryManager.BATTERY_STATUS_CHARGING ||
                               status == BatteryManager.BATTERY_STATUS_FULL

                // Simple battery info following the exact specification
                val batteryInfo = JSONObject().apply {
                    put("batteryLevel", batteryLevel)
                    put("isCharging", isCharging)
                }

                val result = batteryInfo.toString()
                mapOf("info" to result)
            } catch (e: Exception) {
                mapOf("info" to "{\"error\": \"${e.message}\"}")
            }
        }
    }
}
