import Foundation
import BackgroundTasks

// C functions from PHP.c — scheduler runtime (ephemeral TSRM context)
@_silgen_name("scheduler_php_boot")
private func _scheduler_php_boot(_ bootstrapPath: UnsafePointer<CChar>?) -> Int32

@_silgen_name("scheduler_php_artisan")
private func _scheduler_php_artisan(_ command: UnsafePointer<CChar>?) -> UnsafePointer<CChar>?

@_silgen_name("scheduler_php_shutdown")
private func _scheduler_php_shutdown()

@_silgen_name("scheduler_php_is_booted")
private func _scheduler_php_is_booted() -> Int32

/// Scheduler that runs periodic `schedule:run` via BGTaskScheduler.
///
/// Each invocation is ephemeral: boots a dedicated scheduler TSRM context,
/// runs the artisan command, and shuts down. Mirrors Android's
/// PHPSchedulerWorker + WorkManager approach.
///
/// Uses `BGProcessingTask` which can run for minutes and works even when
/// the app has been terminated by the system (cold boot).
final class PHPScheduler {
    static let shared = PHPScheduler()

    /// BGTaskScheduler identifiers — must match Info.plist BGTaskSchedulerPermittedIdentifiers
    static let processingIdentifier = "com.nativephp.scheduler.run"
    static let refreshIdentifier = "com.nativephp.scheduler.refresh"

    /// Minimum interval between runs (iOS minimum is ~15 minutes)
    private let minimumInterval: TimeInterval = 15 * 60

    /// Merged constraints from all background tasks (read from manifest)
    private var mergedConstraints: [String: Any] = [:]

    private init() {}

    // MARK: - Registration

    /// Register the background task handler with BGTaskScheduler.
    /// Must be called before app finishes launching (in init or application:didFinishLaunching).
    func registerBackgroundTasks() {
        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: PHPScheduler.processingIdentifier,
            using: nil
        ) { task in
            guard let processingTask = task as? BGProcessingTask else { return }
            self.handleScheduleRun(task: processingTask)
        }
        NSLog("PHPScheduler: registered BGProcessingTask handler")

        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: PHPScheduler.refreshIdentifier,
            using: nil
        ) { task in
            guard let refreshTask = task as? BGAppRefreshTask else { return }
            self.handleScheduleRun(task: refreshTask)
        }
        NSLog("PHPScheduler: registered BGAppRefreshTask handler")
    }

    // MARK: - Scheduling

    /// Schedule the next background processing task.
    /// Call after persistent boot and on entering background.
    func scheduleNextRun() {
        // Always refresh constraints from disk before scheduling
        loadConstraintsFromManifest()

        let request = BGProcessingTaskRequest(identifier: PHPScheduler.processingIdentifier)
        request.earliestBeginDate = Date(timeIntervalSinceNow: minimumInterval)

        // Apply merged constraints from manifest
        if let network = mergedConstraints["network"] as? String, network == "connected" || network == "unmetered" {
            request.requiresNetworkConnectivity = true
        } else {
            request.requiresNetworkConnectivity = false
        }

        if let charging = mergedConstraints["charging"] as? Bool, charging {
            request.requiresExternalPower = true
        } else {
            request.requiresExternalPower = false
        }

        do {
            try BGTaskScheduler.shared.submit(request)
            NSLog("PHPScheduler: scheduled next processing run in ~%.0f minutes (network=%@, power=%@)",
                  minimumInterval / 60,
                  request.requiresNetworkConnectivity ? "yes" : "no",
                  request.requiresExternalPower ? "yes" : "no")
        } catch {
            NSLog("PHPScheduler: failed to schedule processing task: %@", error.localizedDescription)
        }
    }

    /// Schedule the next background app refresh task.
    /// BGAppRefreshTask runs more frequently (~15-30 min) but for shorter durations.
    func scheduleNextRefresh() {
        let request = BGAppRefreshTaskRequest(identifier: PHPScheduler.refreshIdentifier)
        request.earliestBeginDate = Date(timeIntervalSinceNow: minimumInterval)

        do {
            try BGTaskScheduler.shared.submit(request)
            NSLog("PHPScheduler: scheduled next refresh in ~%.0f minutes", minimumInterval / 60)
        } catch {
            NSLog("PHPScheduler: failed to schedule refresh task: %@", error.localizedDescription)
        }
    }

    // MARK: - Execution

    /// Handle a background task invocation (works with both BGProcessingTask and BGAppRefreshTask).
    /// Ephemeral lifecycle: boot → artisan schedule:run → shutdown.
    private func handleScheduleRun(task: BGTask) {
        let taskType = task is BGProcessingTask ? "processing" : "refresh"
        NSLog("PHPScheduler: handleScheduleRun started (type: %@)", taskType)

        // Schedule the next runs before we start (in case we get killed)
        scheduleNextRun()
        scheduleNextRefresh()

        // Set up expiration handler
        task.expirationHandler = {
            NSLog("PHPScheduler: %@ task expiring, shutting down scheduler runtime", taskType)
            if _scheduler_php_is_booted() != 0 {
                _scheduler_php_shutdown()
            }
        }

        // Run on a background thread (BGTask callback is on an arbitrary queue)
        let appPath = AppUpdateManager.shared.getAppPath()
        let bootstrapPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"

        NSLog("PHPScheduler: booting scheduler runtime")

        let bootResult = _scheduler_php_boot(bootstrapPath)
        if bootResult != 0 {
            NSLog("PHPScheduler: boot FAILED (%d)", bootResult)
            task.setTaskCompleted(success: false)
            return
        }

        // Run each scheduled command in-process instead of `schedule:run`,
        // which tries to fork a `php` subprocess that doesn't exist on iOS.
        let commands = loadCommandsFromManifest()
        if commands.isEmpty {
            NSLog("PHPScheduler: no commands found in manifest")
        }
        for command in commands {
            NSLog("PHPScheduler: running in-process: %@", command)
            let output = artisan(command: command)
            NSLog("PHPScheduler: %@ output: %@", command, output.isEmpty ? "(empty)" : String(output.prefix(500)))
        }

        NSLog("PHPScheduler: shutting down scheduler runtime")
        _scheduler_php_shutdown()

        NSLog("PHPScheduler: completed successfully")
        task.setTaskCompleted(success: true)
    }

    // MARK: - Constraints

    /// Read background_tasks.json and return the list of artisan command names.
    private func loadCommandsFromManifest() -> [String] {
        let appPath = AppUpdateManager.shared.getAppPath()
        let manifestPath = appPath + "/storage/app/background_tasks.json"

        guard FileManager.default.fileExists(atPath: manifestPath),
              let data = FileManager.default.contents(atPath: manifestPath),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let tasks = json["tasks"] as? [[String: Any]] else {
            NSLog("PHPScheduler: no manifest at app storage path, trying Application Support")
            return loadCommandsFromAppSupportManifest()
        }

        return tasks.compactMap { $0["command"] as? String }
    }

    private func loadCommandsFromAppSupportManifest() -> [String] {
        let storageDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let manifestPath = storageDir.appendingPathComponent("storage/app/background_tasks.json").path

        guard FileManager.default.fileExists(atPath: manifestPath),
              let data = FileManager.default.contents(atPath: manifestPath),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let tasks = json["tasks"] as? [[String: Any]] else {
            return []
        }

        return tasks.compactMap { $0["command"] as? String }
    }

    /// Read background_tasks.json from app storage and merge all task constraints.
    /// Union strategy: if any task needs network, the merged result needs network.
    private func loadConstraintsFromManifest() {
        let storageDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let manifestPath = storageDir.appendingPathComponent("storage/app/background_tasks.json").path

        guard FileManager.default.fileExists(atPath: manifestPath),
              let data = FileManager.default.contents(atPath: manifestPath),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let tasks = json["tasks"] as? [[String: Any]] else {
            NSLog("PHPScheduler: no background_tasks.json found, using default constraints")
            mergedConstraints = [:]
            return
        }

        var merged: [String: Any] = [:]

        for task in tasks {
            guard let constraints = task["constraints"] as? [String: Any] else { continue }

            // Network: prefer "unmetered" over "connected" (stricter wins)
            if let network = constraints["network"] as? String {
                let current = merged["network"] as? String
                if current == nil || (current == "connected" && network == "unmetered") {
                    merged["network"] = network
                }
            }

            // Boolean constraints: union (any true → true)
            for key in ["charging", "battery_not_low", "storage_not_low", "device_idle"] {
                if let val = constraints[key] as? Bool, val {
                    merged[key] = true
                }
            }
        }

        mergedConstraints = merged
        NSLog("PHPScheduler: loaded constraints from manifest: %@", String(describing: merged))
    }

    // MARK: - Artisan Helper

    private func artisan(command: String) -> String {
        guard let resultPtr = _scheduler_php_artisan(command) else {
            return ""
        }
        let result = String(cString: resultPtr)
        free(UnsafeMutableRawPointer(mutating: resultPtr))
        return result
    }
}
