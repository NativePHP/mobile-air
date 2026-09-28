package com.nativephp.mobile.bridge.functions

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Functions related to the device's network connection
 * Namespace: "Network.*"
 *
 * The native half of `Native\Mobile\Network::status()`.
 * iOS twin: `Bridge/Functions/NetworkFunctions.swift`.
 */
object NetworkFunctions {

    /**
     * Get the current network status
     * Parameters: none
     * Returns:
     *   - connected: boolean - Whether the device has a usable network path
     *   - type: string - wifi, cellular, ethernet, or unknown
     *   - isExpensive: boolean - Whether the connection is metered
     *   - isConstrained: boolean - Whether Data Saver is restricting this app
     *
     * "connected" means the device has a path to a network, not that any
     * particular server answers.
     */
    class Status(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val manager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
                ?: return emptyMap()

            val capabilities = manager.activeNetwork?.let { manager.getNetworkCapabilities(it) }

            return mapOf(
                "connected" to (capabilities?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) == true),
                "type" to interfaceType(capabilities),
                "isExpensive" to (capabilities?.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED) == false),
                "isConstrained" to (manager.restrictBackgroundStatus == ConnectivityManager.RESTRICT_BACKGROUND_STATUS_ENABLED),
            )
        }

        /** Same order and vocabulary as the iOS twin. */
        private fun interfaceType(capabilities: NetworkCapabilities?): String = when {
            capabilities == null -> "unknown"
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "cellular"
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "ethernet"
            else -> "unknown"
        }
    }
}
