import Foundation

/// Registry for NativePHP plugins to register initialization callbacks.
///
/// Plugins can register callbacks that run at specific lifecycle points:
/// - `onAppLaunch`: Called during app initialization (before WebView loads)
/// - `onAppReady`: Called after PHP runtime has booted successfully
///
/// Example usage in a plugin:
/// ```swift
/// NativePHPPluginRegistry.shared.registerOnAppReady("BackgroundTasks") {
///     PHPScheduler.shared.scheduleNextRun()
/// }
/// ```
///
/// This allows plugins to inject initialization code without modifying core files.
public class NativePHPPluginRegistry {
    public static let shared = NativePHPPluginRegistry()

    private var onAppLaunchCallbacks: [(name: String, callback: () -> Void)] = []
    private var onAppReadyCallbacks: [(name: String, callback: () -> Void)] = []

    private init() {}

    // MARK: - Registration

    /// Register a callback to run during app launch (before WebView loads).
    /// This runs on the main thread.
    ///
    /// - Parameters:
    ///   - name: Identifier for the plugin (for logging)
    ///   - callback: The initialization code to run
    public func registerOnAppLaunch(_ name: String, callback: @escaping () -> Void) {
        onAppLaunchCallbacks.append((name: name, callback: callback))
        print("NativePHPPluginRegistry: Registered '\(name)' for onAppLaunch")
    }

    /// Register a callback to run after PHP runtime has booted successfully.
    ///
    /// - Parameters:
    ///   - name: Identifier for the plugin (for logging)
    ///   - callback: The code to run
    public func registerOnAppReady(_ name: String, callback: @escaping () -> Void) {
        onAppReadyCallbacks.append((name: name, callback: callback))
        print("NativePHPPluginRegistry: Registered '\(name)' for onAppReady")
    }

    // MARK: - Execution (called by core)

    /// Execute all registered onAppLaunch callbacks.
    /// Called by NativePHPApp during initialization.
    internal func executeOnAppLaunch() {
        print("NativePHPPluginRegistry: Executing \(onAppLaunchCallbacks.count) onAppLaunch callbacks")
        for (name, callback) in onAppLaunchCallbacks {
            print("NativePHPPluginRegistry: Running '\(name)' onAppLaunch")
            callback()
        }
    }

    /// Execute all registered onAppReady callbacks.
    /// Called by NativePHPApp after PHP runtime has booted.
    internal func executeOnAppReady() {
        print("NativePHPPluginRegistry: Executing \(onAppReadyCallbacks.count) onAppReady callbacks")
        for (name, callback) in onAppReadyCallbacks {
            print("NativePHPPluginRegistry: Running '\(name)' onAppReady")
            callback()
        }
    }
}
