package com.nativephp.mobile.utils

import android.app.AlertDialog
import android.content.Context
import android.content.DialogInterface
import android.content.Intent
import android.net.Uri
import android.os.*
import android.util.Log
import android.util.TypedValue
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

    fun showAlert(context: Context, title: String, message: String, buttons: Array<String>, styles: Array<String>, onButtonClick: (Int, String) -> Unit) {
        Handler(Looper.getMainLooper()).post {
            try {
                val alertBuilder = AlertDialog.Builder(context)
                    .setTitle(title)
                    .setMessage(message)

                // If no buttons provided, default to "OK"
                val buttonLabels = if (buttons.isEmpty()) arrayOf("OK") else buttons
                val buttonStyles = Array(buttonLabels.size) { styles.getOrElse(it) { "default" } }

                if (buttonLabels.size > 3) {
                    // Android AlertDialog only supports 3 buttons max
                    Log.w(TAG, "⚠️ AlertDialog only supports up to 3 buttons, ignoring: ${buttonLabels.drop(3).joinToString()}")
                }

                // Slots are assigned by index (positive, negative, neutral),
                // then a "cancel"-styled button is swapped into the negative
                // slot — Android's conventional dismiss position.
                val order = buttonLabels.indices.take(3).toMutableList()
                val cancelPos = order.indexOfFirst { buttonStyles[it] == "cancel" }
                if (cancelPos >= 0 && order.size >= 2 && cancelPos != 1) {
                    val swapped = order[1]
                    order[1] = order[cancelPos]
                    order[cancelPos] = swapped
                }

                val slots = intArrayOf(AlertDialog.BUTTON_POSITIVE, AlertDialog.BUTTON_NEGATIVE, AlertDialog.BUTTON_NEUTRAL)
                val destructiveSlots = mutableListOf<Int>()
                order.forEachIndexed { slotPos, index ->
                    val buttonLabel = buttonLabels[index]
                    val listener = DialogInterface.OnClickListener { dialog, _ ->
                        onButtonClick(index, buttonLabel)
                        dialog.dismiss()
                    }
                    when (slots[slotPos]) {
                        AlertDialog.BUTTON_POSITIVE -> alertBuilder.setPositiveButton(buttonLabel, listener)
                        AlertDialog.BUTTON_NEGATIVE -> alertBuilder.setNegativeButton(buttonLabel, listener)
                        AlertDialog.BUTTON_NEUTRAL -> alertBuilder.setNeutralButton(buttonLabel, listener)
                    }
                    if (buttonStyles[index] == "destructive") {
                        destructiveSlots.add(slots[slotPos])
                    }
                }

                val dialog = alertBuilder.show()

                if (destructiveSlots.isNotEmpty()) {
                    val errorColor = resolveErrorColor(context)
                    destructiveSlots.forEach { slot ->
                        dialog.getButton(slot)?.setTextColor(errorColor)
                    }
                }

                Log.d(TAG, "✅ Alert displayed with ${order.size} buttons")
            } catch (e: Exception) {
                Log.e(TAG, "❌ Error showing alert: ${e.message}", e)
            }
        }
    }

    private fun resolveErrorColor(context: Context): Int {
        val typedValue = TypedValue()
        return if (context.theme.resolveAttribute(android.R.attr.colorError, typedValue, true)) {
            typedValue.data
        } else {
            0xFFB3261E.toInt() // Material default error red
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
