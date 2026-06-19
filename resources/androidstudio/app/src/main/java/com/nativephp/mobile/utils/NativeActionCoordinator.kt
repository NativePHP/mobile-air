package com.nativephp.mobile.utils

import android.util.Log
import android.webkit.WebView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
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
        Log.d("NativeActionCoordinator", "🚨 launchAlert called with title: '$title', message: '$message', buttons: ${buttons.contentToString()}, id: '$id', eventClass: '$eventClass'")

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
            // Queue if the WebView hasn't loaded a NativePHP page yet. Without this gate,
            // dispatches during cold boot run against about:blank — the relative fetch
            // fails to parse, the native-event listener isn't attached, and the event
            // is silently dropped (or worse, leaves the WebView in a broken state).
            if (!webViewReady) {
                Log.d("NativeActionCoordinator", "⏳ WebView not ready — queueing event: $event")
                synchronized(pendingEvents) {
                    pendingEvents.add(event to payloadJson)
                }
                return
            }

            Log.d("JSFUNC", "native:$event");
            Log.d("JSFUNC", "$payloadJson");
            val eventForJs = event.replace("\\", "\\\\")
            val js = """
                (function () {
                    try {
                        const payload = $payloadJson;

                        const detail = { event: "$eventForJs", payload };

                        document.dispatchEvent(new CustomEvent("native-event", { detail }));

                        if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
                            window.Livewire.dispatch("native:$eventForJs", payload);
                        }

                        fetch('http://127.0.0.1/_native/api/events', {
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
                          .catch(error => console.error("API Event Dispatch Error:", error && error.message));
                    } catch (e) {
                        console.error("API Event Dispatch threw:", e && e.message);
                    }
                })();
            """.trimIndent()

            Log.d("NativeActionCoordinator", "📢 Dispatching JS event: $event")

            (activity as? WebViewProvider)?.getWebView()?.evaluateJavascript(js, null)
        }


    companion object {
        // Events dispatched before the first NativePHP page has finished loading are
        // queued here and replayed once the WebView is ready. The flag is reset in
        // MainActivity.onCreate so cold boots start in the "not ready" state.
        private val pendingEvents = mutableListOf<Pair<String, String>>()

        @Volatile
        private var webViewReady: Boolean = false

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

        /**
         * Signal that the WebView has finished loading a NativePHP page and is safe
         * to receive dispatches. Queued events from before this point are replayed.
         */
        fun markWebViewReady(activity: FragmentActivity) {
            webViewReady = true

            val toReplay = synchronized(pendingEvents) {
                val copy = pendingEvents.toList()
                pendingEvents.clear()
                copy
            }

            if (toReplay.isEmpty()) return

            Log.d("NativeActionCoordinator", "▶️ Replaying ${toReplay.size} queued event(s)")
            val coordinator = install(activity)
            toReplay.forEach { (event, payloadJson) ->
                coordinator.dispatch(event, payloadJson)
            }
        }

        /**
         * Mark the WebView as not ready. Called from MainActivity.onCreate so that
         * a cold boot — including the one triggered when Android kills the app while
         * the user is in Settings — restarts in the queueing state.
         */
        fun markWebViewNotReady() {
            webViewReady = false
        }
    }
}
