package com.nativephp.mobile.security

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.security.KeyStore
import java.security.SecureRandom
import javax.crypto.AEADBadTagException
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Holds Laravel's APP_KEY (and any keys it replaced) encrypted under a key
 * that never leaves the Android Keystore. The ciphertext lives in
 * noBackupFilesDir: the Keystore key does not travel with a backup, so a
 * restored copy could never be decrypted anyway.
 */
class AppKeyStore(context: Context) {
    data class Keys(val current: String, val previous: List<String>)

    data class Result(val keys: Keys, val migrated: Boolean)

    private val storeFile = File(context.noBackupFilesDir, STORE_FILE)

    /**
     * Returns the keys for this install, creating them on first launch.
     *
     * Earlier versions kept the key as plain text in [legacyFile]. When that
     * file is found its key moves in here and the file is deleted. If the
     * app's Laravel can decrypt with previous keys ([canRotate]) the legacy
     * key is retired to APP_PREVIOUS_KEYS and a fresh key takes over, so
     * nothing new is encrypted with a key that sat on disk unprotected.
     */
    fun load(legacyFile: File, canRotate: () -> Boolean): Result {
        read()?.let { stored ->
            // A crash between write() and delete() below leaves both behind.
            if (legacyFile.exists()) legacyFile.delete()
            return Result(stored, migrated = false)
        }

        val legacyKey = readLegacyKey(legacyFile)
        val keys = when {
            legacyKey == null -> Keys(generateKey(), emptyList())
            canRotate() -> Keys(generateKey(), listOf(legacyKey))
            else -> Keys(legacyKey, emptyList())
        }

        // Throws on failure, leaving the legacy file in place for next launch.
        write(keys)

        if (legacyFile.exists()) legacyFile.delete()

        if (legacyKey != null) {
            Log.d(TAG, "🔐 Moved legacy APP_KEY into the Keystore (rotated: ${keys.current != legacyKey})")
        } else {
            Log.d(TAG, "🔐 Generated new APP_KEY")
        }

        return Result(keys, migrated = legacyKey != null)
    }

    private fun readLegacyKey(file: File): String? {
        if (!file.exists()) return null
        return file.readText().trim().takeIf { it.startsWith(KEY_PREFIX) }
    }

    /**
     * Null when nothing is stored, or when what is stored can never be read
     * again (Keystore key gone, or ciphertext not made by it). Any other
     * failure is thrown: replacing the key after a passing Keystore error
     * would orphan everything encrypted with it.
     */
    private fun read(): Keys? {
        if (!storeFile.exists()) return null

        val secretKey = keystore().getKey(KEYSTORE_ALIAS, null) as? SecretKey
        if (secretKey == null) {
            Log.w(TAG, "⚠️ Keystore key is gone, stored APP_KEY is unrecoverable")
            return null
        }

        val blob = storeFile.readBytes()
        if (blob.size <= IV_LENGTH) return null

        return try {
            val cipher = Cipher.getInstance(TRANSFORMATION)
            cipher.init(Cipher.DECRYPT_MODE, secretKey, GCMParameterSpec(TAG_BITS, blob, 0, IV_LENGTH))
            val json = JSONObject(String(cipher.doFinal(blob, IV_LENGTH, blob.size - IV_LENGTH), Charsets.UTF_8))
            val previous = json.optJSONArray("previous") ?: JSONArray()
            Keys(json.getString("key"), List(previous.length()) { previous.getString(it) })
        } catch (e: AEADBadTagException) {
            Log.w(TAG, "⚠️ Stored APP_KEY failed authentication, it is unrecoverable")
            null
        }
    }

    private fun write(keys: Keys) {
        val json = JSONObject()
            .put("key", keys.current)
            .put("previous", JSONArray(keys.previous))

        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateSecretKey())
        val blob = cipher.iv + cipher.doFinal(json.toString().toByteArray(Charsets.UTF_8))

        val tmp = File(storeFile.parentFile, "$STORE_FILE.tmp")
        tmp.writeBytes(blob)
        if (!tmp.renameTo(storeFile)) {
            tmp.delete()
            throw IllegalStateException("Could not save APP_KEY store")
        }
    }

    private fun keystore(): KeyStore = KeyStore.getInstance(KEYSTORE_PROVIDER).apply { load(null) }

    private fun getOrCreateSecretKey(): SecretKey {
        (keystore().getKey(KEYSTORE_ALIAS, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE_PROVIDER)
        generator.init(
            KeyGenParameterSpec.Builder(
                KEYSTORE_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )
        return generator.generateKey()
    }

    // APP_KEY is just 32 random bytes, base64-encoded — generate it locally
    // instead of booting PHP just to run key:generate (matches iOS).
    private fun generateKey(): String {
        val keyBytes = ByteArray(32)
        SecureRandom().nextBytes(keyBytes)
        return KEY_PREFIX + Base64.encodeToString(keyBytes, Base64.NO_WRAP)
    }

    companion object {
        private const val TAG = "AppKeyStore"
        private const val KEYSTORE_PROVIDER = "AndroidKeyStore"
        private const val KEYSTORE_ALIAS = "nativephp_app_key"
        private const val STORE_FILE = "nativephp_app_key.bin"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val KEY_PREFIX = "base64:"
        private const val IV_LENGTH = 12
        private const val TAG_BITS = 128
    }
}
