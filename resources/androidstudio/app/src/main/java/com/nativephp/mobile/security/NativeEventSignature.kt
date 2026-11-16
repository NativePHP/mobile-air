package com.nativephp.mobile.security

import android.util.Base64
import java.security.SecureRandom
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * Signs the device events the native shell POSTs to `/_native/api/events`, so
 * PHP can tell a genuine native event from one a web view page forged.
 *
 * A page can POST to that endpoint itself (the `api` routes carry no
 * middleware), so without a signature a script — an XSS, or a third-party
 * script the app bundles — could fake a `PaymentCompleted` or a
 * `Biometric.Completed(success: true)` and fire the app's listeners and pending
 * callbacks as if the device sent it. The shell signs each event with the app
 * key, which lives in the Keystore and is never handed to the page, over a
 * per-event nonce, a timestamp and the exact body bytes. PHP verifies with the
 * same key and refuses anything stale, replayed or unsigned once enforcement is
 * on (`NATIVEPHP_EVENTS_SIGNED`).
 *
 * The purpose string, message layout and header names match
 * `Native\Mobile\Support\NativeEventSignature` on the PHP side byte for byte.
 * Change the two together. iOS twin: `Bridge/NativeEventSignature.swift`.
 */
object NativeEventSignature {
    const val NONCE_HEADER = "X-Native-Event-Nonce"
    const val TIMESTAMP_HEADER = "X-Native-Event-Timestamp"
    const val SIGNATURE_HEADER = "X-Native-Event-Signature"

    /** What the signature is for, mixed in so one made for something else can't pass. */
    private const val PURPOSE = "nativephp.native-event"

    private const val KEY_PREFIX = "base64:"

    private val random = SecureRandom()

    /**
     * The app key as raw bytes, matching PHP's `app('encrypter')->getKey()`.
     * Set once at shell boot; Java never sees the native PHP env, so the key is
     * handed over directly rather than read back from the environment.
     */
    @Volatile
    private var rawKey: ByteArray? = null

    data class Signed(val nonce: String, val timestamp: String, val signature: String)

    /** Remember the app key (`base64:…`) for signing. Called at shell boot. */
    fun configure(appKey: String) {
        rawKey = if (appKey.startsWith(KEY_PREFIX)) {
            try {
                Base64.decode(appKey.substring(KEY_PREFIX.length), Base64.NO_WRAP)
            } catch (e: Exception) {
                null
            }
        } else {
            null
        }
    }

    /**
     * Sign `body` — the exact bytes that will be POSTed. Null when no app key
     * is set; the caller then posts unsigned, and PHP, if it is enforcing, drops
     * the event rather than trusting it.
     */
    fun sign(body: String): Signed? {
        val key = rawKey ?: return null

        val nonce = randomHex(16)
        val timestamp = (System.currentTimeMillis() / 1000L).toString()
        val message = PURPOSE + "\n" + nonce + "\n" + timestamp + "\n" + body

        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(key, "HmacSHA256"))
        val signature = mac.doFinal(message.toByteArray(Charsets.UTF_8)).toHex()

        return Signed(nonce, timestamp, signature)
    }

    private fun randomHex(byteCount: Int): String {
        val bytes = ByteArray(byteCount)
        random.nextBytes(bytes)
        return bytes.toHex()
    }

    private fun ByteArray.toHex(): String = joinToString("") { "%02x".format(it) }
}
