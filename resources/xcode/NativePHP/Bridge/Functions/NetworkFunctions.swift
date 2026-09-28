import Foundation
import Network

/// Functions related to the device's network connection
/// Namespace: "Network.*"
///
/// The native half of `Native\Mobile\Network::status()`.
/// Android twin: `bridge/functions/NetworkFunctions.kt`.
enum NetworkFunctions {

    // MARK: - Path Observation

    /// The device's current network path, kept current in the background.
    ///
    /// `NWPathMonitor` is a push API and `BridgeFunction.execute` is
    /// synchronous, so the latest path is held here and answered from. The
    /// monitor runs for the life of the process: starting one per call costs a
    /// round trip to the network daemon before it delivers anything.
    ///
    /// Only the first call can arrive before the first path does, so only that
    /// call waits briefly for it. Guessing instead could answer "offline" on a
    /// working connection.
    private final class PathObserver {
        static let shared = PathObserver()

        private let monitor = NWPathMonitor()
        private let queue = DispatchQueue(label: "com.nativephp.network.path")
        private let lock = NSLock()
        private let firstPath = DispatchSemaphore(value: 0)

        private var latest: NWPath?
        private var signalled = false

        private init() {
            monitor.pathUpdateHandler = { [weak self] path in
                guard let self else { return }

                self.lock.lock()
                self.latest = path
                let isFirst = !self.signalled
                self.signalled = true
                self.lock.unlock()

                if isFirst {
                    self.firstPath.signal()
                }
            }

            monitor.start(queue: queue)
        }

        /// The latest path, waiting up to `timeout` for the first one only.
        /// Returns nil if none has arrived yet.
        func currentPath(waitingUpTo timeout: TimeInterval) -> NWPath? {
            lock.lock()
            let known = latest
            lock.unlock()

            if let known {
                return known
            }

            _ = firstPath.wait(timeout: .now() + timeout)

            lock.lock()
            defer { lock.unlock() }

            return latest
        }
    }

    // MARK: - Network.Status

    /// Get the current network status
    /// Parameters: none
    /// Returns:
    ///   - connected: boolean - Whether the device has a usable network path
    ///   - type: string - wifi, cellular, ethernet, or unknown
    ///   - isExpensive: boolean - Whether the path is metered (cellular, hotspot)
    ///   - isConstrained: boolean - Whether Low Data Mode is enabled
    ///
    /// "connected" means the device has a path to a network, not that any
    /// particular server answers.
    class Status: BridgeFunction {
        /// Only ever paid once, before the monitor's first delivery.
        private static let firstPathTimeout: TimeInterval = 0.5

        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let path = PathObserver.shared.currentPath(waitingUpTo: Status.firstPathTimeout) else {
                print("Network.Status called before the first path arrived")

                return [:]
            }

            return [
                "connected": path.status == .satisfied,
                "type": Status.interfaceType(of: path),
                "isExpensive": path.isExpensive,
                "isConstrained": path.isConstrained,
            ]
        }

        /// A path can use several interfaces at once; they are checked in the
        /// order that best describes the connection to a person.
        private static func interfaceType(of path: NWPath) -> String {
            if path.usesInterfaceType(.wifi) {
                return "wifi"
            }

            if path.usesInterfaceType(.cellular) {
                return "cellular"
            }

            if path.usesInterfaceType(.wiredEthernet) {
                return "ethernet"
            }

            return "unknown"
        }
    }
}
