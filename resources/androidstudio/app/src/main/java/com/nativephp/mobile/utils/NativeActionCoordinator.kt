package com.nativephp.mobile.utils

import android.util.Log
import android.webkit.WebView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONObject

interface WebViewProvider {
    fun getWebView(): WebView

    /**
     * The WebView if one has been created — native-first boots have none
     * until a web response needs painting. Hot paths (event dispatch) use
     * this so they never force Chromium into a native-only process.
     */
    fun getWebViewOrNull(): WebView? = null
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

    fun launchAlert(title: String, message: String, buttons: Array<String>, styles: Array<String>, id: String?, eventClass: String?) {
        Log.d("NativeActionCoordinator", "🚨 launchAlert called with title: '$title', message: '$message', buttons: ${buttons.contentToString()}, styles: ${styles.contentToString()}, id: '$id', eventClass: '$eventClass'")

        val context = requireContext()

        // Use default event class if not provided
        val finalEventClass = eventClass ?: "Native\\Mobile\\Events\\Alert\\ButtonPressed"

        // Use NativeActions to show the alert with callback
        NativeActions.showAlert(context, title, message, buttons, styles) { index, label ->
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

    /**
     * Route the event through NativeElementBridge.sendNativeEvent — the
     * single dispatch channel. It feeds the EDGE element queue and hands
     * the event to the web sink MainActivity installs, so each screen
     * mode hears it exactly once through its own delivery arm.
     */
    private fun dispatch(event: String, payloadJson: String) {
        Log.d("NativeActionCoordinator", "📢 Dispatching event: $event")

        try {
            NativeElementBridge.sendNativeEvent(event, payloadJson)
        } catch (e: Exception) {
            Log.d("NativeActionCoordinator", "Event dispatch failed: ${e.message}")
        }
    }


    companion object {

        /**
         * Deliver a native event to the current web page: a `native-event`
         * CustomEvent, a Livewire dispatch, and a POST to /_native/api/events
         * so PHP-side listeners fire. Called by MainActivity's web event
         * sink; must run on the main thread.
         */
        fun dispatchToWebView(webView: WebView, event: String, payloadJson: String) {
            val eventForJs = event.replace("\\", "\\\\")
            val js = """
                (function () {
                    const payload = $payloadJson;

                    const detail = { event: "$eventForJs", payload };

                    document.dispatchEvent(new CustomEvent("native-event", { detail }));

                    if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
                        window.Livewire.dispatch("native:$eventForJs", payload);
                    }

                    fetch('/_native/api/events', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            event: "$eventForJs",
                            payload: payload
                        })
                    }).then(response => response.json())
                      .then(data => {
                          if (data.message && data.message.includes("Unknown named parameter")) {
                              console.log("API Event Dispatch: Parameter issue detected");
                          } else {
                              console.log("API Event Dispatch Success");
                          }
                      })
                      .catch(error => console.error("API Event Dispatch Error:", error.message));
                })();
            """.trimIndent()

            Log.d("NativeActionCoordinator", "📢 Injecting JS event: $event")

            webView.evaluateJavascript(js, null)
        }
        fun install(activity: FragmentActivity): NativeActionCoordinator =
            activity.supportFragmentManager.findFragmentByTag("NativeActionCoordinator") as? NativeActionCoordinator
                ?: NativeActionCoordinator().also {
                    // install() is reached from the deferred boot callback, which can
                    // land after the user backgrounds the app mid-boot — i.e. after
                    // onSaveInstanceState. A plain commitNow() then throws
                    // IllegalStateException and kills the app. State loss is fine:
                    // this fragment is headless and install() re-adds it on demand.
                    activity.supportFragmentManager.beginTransaction()
                        .add(it, "NativeActionCoordinator")
                        .commitNowAllowingStateLoss()
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
