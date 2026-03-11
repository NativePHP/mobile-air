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

    /// BGTaskScheduler identifier — must match Info.plist BGTaskSchedulerPermittedIdentifiers
    static let taskIdentifier = "com.nativephp.scheduler.run"

    /// Minimum interval between runs (iOS minimum is ~15 minutes)
    private let minimumInterval: TimeInterval = 15 * 60

    private init() {}

    // MARK: - Registration

    /// Register the background task handler with BGTaskScheduler.
    /// Must be called before app finishes launching (in init or application:didFinishLaunching).
    func registerBackgroundTasks() {
        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: PHPScheduler.taskIdentifier,
            using: nil
        ) { task in
            guard let processingTask = task as? BGProcessingTask else { return }
            self.handleScheduleRun(task: processingTask)
        }

        NSLog("PHPScheduler: registered BGProcessingTask handler")
    }

    // MARK: - Scheduling

    /// Schedule the next background processing task.
    /// Call after persistent boot and on entering background.
    func scheduleNextRun() {
        let request = BGProcessingTaskRequest(identifier: PHPScheduler.taskIdentifier)
        request.earliestBeginDate = Date(timeIntervalSinceNow: minimumInterval)
        request.requiresNetworkConnectivity = false
        request.requiresExternalPower = false

        do {
            try BGTaskScheduler.shared.submit(request)
            NSLog("PHPScheduler: scheduled next run in ~%.0f minutes", minimumInterval / 60)
        } catch {
            NSLog("PHPScheduler: failed to schedule: %@", error.localizedDescription)
        }
    }

    // MARK: - Execution

    /// Handle a BGProcessingTask invocation.
    /// Ephemeral lifecycle: boot → artisan schedule:run → shutdown.
    private func handleScheduleRun(task: BGProcessingTask) {
        NSLog("PHPScheduler: handleScheduleRun started")

        // Schedule the next run before we start (in case we get killed)
        scheduleNextRun()

        // Set up expiration handler
        task.expirationHandler = {
            NSLog("PHPScheduler: task expiring, shutting down scheduler runtime")
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

        NSLog("PHPScheduler: running schedule:run")
        let output = artisan(command: "schedule:run")
        NSLog("PHPScheduler: schedule:run output: %@", String(output.prefix(200)))

        NSLog("PHPScheduler: shutting down scheduler runtime")
        _scheduler_php_shutdown()

        NSLog("PHPScheduler: completed successfully")
        task.setTaskCompleted(success: true)
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
