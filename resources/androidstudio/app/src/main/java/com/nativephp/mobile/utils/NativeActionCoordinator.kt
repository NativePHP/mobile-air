package com.nativephp.mobile.utils

import android.util.Log
import android.webkit.WebView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.network.PHPRequest
import com.nativephp.mobile.network.WebViewManager
import org.json.JSONObject

interface WebViewProvider {
    fun getWebView(): WebView
}

class NativeActionCoordinator : Fragment() {

    // File picker launcher
    private val filePicker =
        registerForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
            uri ?: return@registerForActivityResult
            val payload = JSONObject().apply {
                put("uri", uri.toString())
            }
            dispatch("file:chosen", payload.toString())
        }

    fun launchFilePicker(mime: String = "*/*") {
        filePicker.launch(arrayOf(mime))
    }

    fun launchAlert(title: String, message: String, buttons: Array<String>, id: String?, eventClass: String?) {
        Log.d("NativeActionCoordinator", "launchAlert called with title: '$title', message: '$message', buttons: ${buttons.contentToString()}, id: '$id', eventClass: '$eventClass'")

        val context = requireContext()

        // Use default event class if not provided
        val finalEventClass = eventClass ?: "Native\\Mobile\\Events\\Alert\\ButtonPressed"

        // Use NativeActions to show the alert with callback
        NativeActions.showAlert(context, title, message, buttons) { index, label ->
            Log.d("NativeActionCoordinator", "🔘 Alert button clicked: index=$index, label='$label'")

            // Create payload for the ButtonPressed event with optional id
            val payload = JSONObject().apply {
                put("index", index)
                put("label", label)
                if (id != null) {
                    put("id", id)
                }
            }

            // Dispatch the event back to PHP with custom event class
            dispatch(finalEventClass, payload.toString())
        }
    }

    private fun dispatch(event: String, payloadJson: String) {
            Log.d("JSFUNC", "native:$event");
            Log.d("JSFUNC", "$payloadJson");
            val eventForJs = event.replace("\\", "\\\\")
            val js = """
                (function () {
                    const payload = $payloadJson;

                    const detail = { event: "$eventForJs", payload };

                    document.dispatchEvent(new CustomEvent("native-event", { detail }));

                    if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
                        window.Livewire.dispatch("native:$eventForJs", payload);
                    }
                })();
            """.trimIndent()

            Log.d("NativeActionCoordinator", "Dispatching JS event: $event")

            (activity as? WebViewProvider)?.getWebView()?.evaluateJavascript(js, null)

            Thread {
                try {
                    val phpBridge = WebViewManager.shared?.phpBridge
                    if (phpBridge != null) {
                        val requestBody = JSONObject().apply {
                            put("event", event)
                            put("payload", JSONObject(payloadJson))
                        }.toString()
                        val request = PHPRequest(
                            url = "/_native/api/events",
                            method = "POST",
                            body = requestBody,
                            headers = mapOf(
                                "Content-Type" to "application/json",
                                "X-Requested-With" to "XMLHttpRequest"
                            )
                        )
                        phpBridge.handleLaravelRequest(request)
                        Log.d("NativeActionCoordinator", "Direct PHP dispatch success for: $event")
                    } else {
                        Log.w("NativeActionCoordinator", "PHPBridge not available, skipping direct dispatch for: $event")
                    }
                } catch (e: Exception) {
                    Log.e("NativeActionCoordinator", "Direct PHP dispatch failed for: $event - ${e.message}")
                }
            }.start()
        }


    companion object {
        fun install(activity: FragmentActivity): NativeActionCoordinator =
            activity.supportFragmentManager.findFragmentByTag("NativeActionCoordinator") as? NativeActionCoordinator
                ?: NativeActionCoordinator().also {
                    activity.supportFragmentManager.beginTransaction()
                        .add(it, "NativeActionCoordinator")
                        .commitNow()
                }

        /**
         * Dispatch an event to PHP from anywhere in the app
         * This is a helper method for activities/fragments that need to dispatch events
         */
        fun dispatchEvent(activity: FragmentActivity, event: String, payloadJson: String) {
            Log.d("NativeActionCoordinator", "📢 Static dispatch event: $event")
            val coordinator = install(activity)
            coordinator.dispatch(event, payloadJson)
        }
    }
}
