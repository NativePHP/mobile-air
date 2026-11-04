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
            Log.d("JSFUNC", "native:$event");
            Log.d("JSFUNC", "$payloadJson");
            val eventForJs = event.replace("\\", "\\\\")

            // The exact body bytes PHP reads back as request()->getContent() and,
            // when enforcing, verifies the signature against. Built by
            // concatenation and posted verbatim — never rebuilt by JSON.stringify,
            // which could reorder keys or renumber values and break the match.
            val body = "{\"event\":" + JSONObject.quote(event) + ",\"payload\":" + payloadJson + "}"

            // A JS string literal that evaluates back to `body` verbatim, so
            // fetch posts exactly these bytes.
            val bodyLiteral = JSONObject.quote(body)

            // Sign on device so PHP can tell this event from one a page forged.
            // The app key lives in the Keystore and never reaches the page. 3.x
            // has no Jump dev session, so events are always signed here; PHP
            // enforces only when NATIVEPHP_EVENTS_SIGNED is set, which the shell
            // sets on-device at boot.
            val signatureHeaders = com.nativephp.mobile.security.NativeEventSignature.sign(body)?.let { s ->
                ",\n'${com.nativephp.mobile.security.NativeEventSignature.NONCE_HEADER}': '${s.nonce}'," +
                    "\n'${com.nativephp.mobile.security.NativeEventSignature.TIMESTAMP_HEADER}': '${s.timestamp}'," +
                    "\n'${com.nativephp.mobile.security.NativeEventSignature.SIGNATURE_HEADER}': '${s.signature}'"
            } ?: ""

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
                            'X-Requested-With': 'XMLHttpRequest'$signatureHeaders
                        },
                        body: $bodyLiteral
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

            Log.d("NativeActionCoordinator", "📢 Dispatching JS event: $event")

            (activity as? WebViewProvider)?.getWebView()?.evaluateJavascript(js, null)
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
