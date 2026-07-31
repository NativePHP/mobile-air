package com.nativephp.mobile.utils

import android.app.AlertDialog
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.*
import android.util.Log
import android.widget.Toast
import androidx.browser.customtabs.CustomTabsIntent

object NativeActions {
    private const val TAG = "NativeActions"

    // vibrate() removed - migrated to god method pattern (DeviceFunctions.Vibrate)

    fun showToast(context: Context, message: String) {
        Handler(Looper.getMainLooper()).post {
            try {
                Toast.makeText(context, message, Toast.LENGTH_LONG).show()
                Log.d(TAG, "✅ Toast displayed")
            } catch (e: Exception) {
                Log.e(TAG, "❌ Error showing toast: ${e.message}", e)
            }
        }
    }

    fun showAlert(context: Context, title: String, message: String, buttons: Array<String>, onButtonClick: (Int, String) -> Unit) {
        Handler(Looper.getMainLooper()).post {
            try {
                val alertBuilder = AlertDialog.Builder(context)
                    .setTitle(title)
                    .setMessage(message)
                
                // If no buttons provided, default to "OK"
                val buttonLabels = if (buttons.isEmpty()) arrayOf("OK") else buttons
                
                // Add buttons dynamically
                buttonLabels.forEachIndexed { index, buttonLabel ->
                    when (index) {
                        0 -> alertBuilder.setPositiveButton(buttonLabel) { dialog, _ -> 
                            onButtonClick(index, buttonLabel)
                            dialog.dismiss()
                        }
                        1 -> alertBuilder.setNegativeButton(buttonLabel) { dialog, _ -> 
                            onButtonClick(index, buttonLabel)
                            dialog.dismiss()
                        }
                        2 -> alertBuilder.setNeutralButton(buttonLabel) { dialog, _ -> 
                            onButtonClick(index, buttonLabel)
                            dialog.dismiss()
                        }
                        else -> {
                            // Android AlertDialog only supports 3 buttons max
                            Log.w(TAG, "⚠️ AlertDialog only supports up to 3 buttons, ignoring button: $buttonLabel")
                        }
                    }
                }
                
                alertBuilder.show()
                Log.d(TAG, "✅ Alert displayed with ${buttonLabels.size} buttons")
            } catch (e: Exception) {
                Log.e(TAG, "❌ Error showing alert: ${e.message}", e)
            }
        }
    }

    fun share(context: Context,title: String, message: String) {
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_SUBJECT, title)
            putExtra(Intent.EXTRA_TEXT, message)
        }
        val chooser = Intent.createChooser(intent, title)
        chooser.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        context.startActivity(chooser)
    }

    // openCamera() removed - migrated to god method pattern (CameraFunctions.GetPhoto via NativeActionCoordinator)

    // toggleFlashlight() removed - migrated to god method pattern (DeviceFunctions.ToggleFlashlight)

    fun openInAppBrowser(context: Context, url: String){
        val intent = CustomTabsIntent.Builder().build()
        intent.launchUrl(context, Uri.parse(url))
    }

    fun openSystemBrowser(context: Context, url: String) {
        val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        context.startActivity(intent)
        Log.d(TAG, "🌐 Opened URL in system browser: $url")
    }

    fun openAuthBrowser(context: Context, url: String) {
        val intent = CustomTabsIntent.Builder()
            .setShowTitle(true)
            .setUrlBarHidingEnabled(false)
            .build()
        intent.launchUrl(context, Uri.parse(url))
        Log.d(TAG, "🔐 Opened URL in auth browser: $url")
    }
}
