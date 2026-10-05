import Foundation
import AVFoundation
import AudioToolbox
import UIKit
import WebKit

// MARK: - Device Function Namespace
//
// Core built-in (migrated from the `nativephp/mobile-device` plugin). Registered
// directly in `BridgeFunctionRegistration.swift` alongside Edge/Perf/UI — no
// plugin install required. Android twin: `bridge/functions/DeviceFunctions.kt`.

enum DeviceFunctions {

    // MARK: - Device.Vibrate

    /// Vibrate the device
    /// Parameters: none
    /// Returns:
    ///   - success: boolean - Whether vibration was triggered
    class Vibrate: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            print("Device.Vibrate called")

            // Trigger haptic feedback
            AudioServicesPlaySystemSound(kSystemSoundID_Vibrate)

            print("Vibration triggered")
            return ["success": true]
        }
    }

    // MARK: - Device.ToggleFlashlight

    /// Toggle the device flashlight on/off
    /// Parameters: none
    /// Returns:
    ///   - success: boolean - Whether flashlight was toggled
    ///   - state: boolean - Current flashlight state (on = true, off = false)
    class ToggleFlashlight: BridgeFunction {
        // Static state to track flashlight across calls
        private static var flashlightState = false

        func execute(parameters: [String: Any]) throws -> [String: Any] {
            print("Device.ToggleFlashlight called")

            guard let device = AVCaptureDevice.default(for: .video), device.hasTorch else {
                print("Flashlight not available on this device")
                return [
                    "success": false,
                    "error": "Flashlight not available"
                ]
            }

            do {
                try device.lockForConfiguration()

                // Toggle the state
                DeviceFunctions.ToggleFlashlight.flashlightState.toggle()

                device.torchMode = DeviceFunctions.ToggleFlashlight.flashlightState ? .on : .off
                device.unlockForConfiguration()

                print("Flashlight toggled to: \(DeviceFunctions.ToggleFlashlight.flashlightState)")

                return [
                    "success": true,
                    "state": DeviceFunctions.ToggleFlashlight.flashlightState
                ]
            } catch {
                print("Failed to toggle flashlight: \(error)")
                return [
                    "success": false,
                    "error": error.localizedDescription
                ]
            }
        }
    }

    // MARK: - Device.GetId

    /// Get the unique device ID
    /// Parameters: none
    /// Returns:
    ///   - id: string - Unique device identifier (identifierForVendor UUID)
    class GetId: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            print("Device.GetId called")

            let deviceId = UIDevice.current.identifierForVendor?.uuidString ?? UUID().uuidString
            print("Device ID retrieved: \(deviceId)")

            return ["id": deviceId]
        }
    }

    // MARK: - Device.GetInfo

    /// Get detailed device information
    /// Parameters: none
    /// Returns:
    ///   - info: JSON string with device details (name, model, platform, osVersion,
    ///     memUsed, memTotal, processorCount, activeProcessorCount, systemUptime, etc.)
    class GetInfo: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            print("Device.GetInfo called")

            let device = UIDevice.current

            // Calculate iOS version number (e.g., "16.3.1" -> 160301)
            let iOSVersionNumber = calculateiOSVersionNumber(device.systemVersion)

            // Check if running in simulator
            let isVirtual = isRunningInSimulator()

            // Get memory usage
            let memUsed = getMemoryUsage()
            let processInfo = ProcessInfo.processInfo

            // Get WebView version
            let webViewVersion = getWebViewVersion()

            // Language (BCP 47 tag, e.g. "en-US")
            let language = Locale.current.identifier(.bcp47)

            let deviceInfo: [String: Any] = [
                "name": device.name,
                "model": device.model,
                "platform": "ios",
                "operatingSystem": device.systemName,
                "osVersion": device.systemVersion,
                "iOSVersion": iOSVersionNumber,
                "manufacturer": "Apple",
                "language": language,
                "isVirtual": isVirtual,
                "memUsed": NSNumber(value: memUsed),
                "memTotal": NSNumber(value: processInfo.physicalMemory),
                "processorCount": processInfo.processorCount,
                "activeProcessorCount": processInfo.activeProcessorCount,
                "systemUptime": processInfo.systemUptime,
                "webViewVersion": webViewVersion
            ]

            print("Device info collected")

            do {
                let jsonData = try JSONSerialization.data(withJSONObject: deviceInfo, options: [])
                if let jsonString = String(data: jsonData, encoding: .utf8) {
                    return ["info": jsonString]
                } else {
                    return ["info": "{\"error\": \"Failed to convert JSON data to string\"}"]
                }
            } catch {
                print("Error serializing device info: \(error)")
                return ["info": "{\"error\": \"\(error.localizedDescription)\"}"]
            }
        }

        private func calculateiOSVersionNumber(_ versionString: String) -> Int {
            let components = versionString.split(separator: ".").compactMap { Int($0) }
            if components.count >= 3 {
                return components[0] * 10000 + components[1] * 100 + components[2]
            } else if components.count == 2 {
                return components[0] * 10000 + components[1] * 100
            } else if components.count == 1 {
                return components[0] * 10000
            }
            return 0
        }

        private func isRunningInSimulator() -> Bool {
            #if targetEnvironment(simulator)
            return true
            #else
            return false
            #endif
        }

        private func getMemoryUsage() -> Int64 {
            var info = mach_task_basic_info()
            var count = mach_msg_type_number_t(MemoryLayout<mach_task_basic_info>.size) / 4

            let kerr: kern_return_t = withUnsafeMutablePointer(to: &info) {
                $0.withMemoryRebound(to: integer_t.self, capacity: 1) {
                    task_info(mach_task_self_, task_flavor_t(MACH_TASK_BASIC_INFO), $0, &count)
                }
            }

            if kerr == KERN_SUCCESS {
                return Int64(info.resident_size)
            } else {
                return -1
            }
        }

        private func getWebViewVersion() -> String {
            // Get WebKit version from the bundle instead of creating a WKWebView
            // Creating WKWebView can crash if not on main thread or without proper configuration
            if let webKitBundle = Bundle(identifier: "com.apple.WebKit") {
                if let version = webKitBundle.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String {
                    return version
                }
            }

            // Fallback: Try to get iOS version as WebKit version is tied to iOS
            return UIDevice.current.systemVersion
        }
    }

    // MARK: - Device.GetBatteryInfo

    /// Get battery information
    /// Parameters: none
    /// Returns:
    ///   - info: JSON string with battery level (0-1) and charging status
    class GetBatteryInfo: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            print("Device.GetBatteryInfo called")

            let device = UIDevice.current
            device.isBatteryMonitoringEnabled = true

            // Battery level as a percentage (0 to 1) - exactly as specified
            let batteryLevel = device.batteryLevel >= 0 ? device.batteryLevel : -1

            // Simple battery info following the exact specification
            let batteryInfo: [String: Any] = [
                "batteryLevel": batteryLevel,
                "isCharging": device.batteryState == .charging
            ]

            print("Battery info retrieved: \(Int(batteryLevel * 100))%, charging: \(device.batteryState == .charging)")

            do {
                let jsonData = try JSONSerialization.data(withJSONObject: batteryInfo, options: [])
                if let jsonString = String(data: jsonData, encoding: .utf8) {
                    device.isBatteryMonitoringEnabled = false
                    return ["info": jsonString]
                } else {
                    device.isBatteryMonitoringEnabled = false
                    return ["info": "{\"error\": \"Failed to convert JSON data to string\"}"]
                }
            } catch {
                print("Error serializing battery info: \(error)")
                device.isBatteryMonitoringEnabled = false
                return ["info": "{\"error\": \"\(error.localizedDescription)\"}"]
            }
        }
    }

    // MARK: - Device.GetThermalState

    /// Current device thermal state, normalized to NativePHP's four-case
    /// vocabulary (`normal` / `warm` / `hot` / `critical`).
    /// Parameters: none
    /// Returns:
    ///   - state: string - "normal" | "warm" | "hot" | "critical"
    class GetThermalState: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            print("Device.GetThermalState called")
            let state = DeviceFunctions.normalizedThermalState()
            print("Thermal state: \(state)")
            return ["state": state]
        }
    }

    /// Map `ProcessInfo.ThermalState` onto the shared NativePHP vocabulary.
    /// iOS has exactly four cases, 1:1 with Normal / Warm / Hot / Critical.
    static func normalizedThermalState() -> String {
        switch ProcessInfo.processInfo.thermalState {
        case .nominal: return "normal"
        case .fair: return "warm"
        case .serious: return "hot"
        case .critical: return "critical"
        @unknown default: return "normal"
        }
    }
}
