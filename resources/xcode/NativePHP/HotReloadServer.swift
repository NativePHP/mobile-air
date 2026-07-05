import Foundation
import Network

class HotReloadServer {
    private var listener: NWListener?
    private let port: NWEndpoint.Port = 9999
    private let queue = DispatchQueue(label: "HotReloadServer")
    private var retryCount = 0
    private let maxRetries = 15

    static let shared = HotReloadServer()

    private init() {}

    func start() {
        guard listener == nil else { return }

        do {
            let params = NWParameters.tcp
            // SO_REUSEADDR: lets us rebind immediately if a just-terminated
            // previous instance left port 9999 in TIME_WAIT.
            params.allowLocalEndpointReuse = true

            let listener = try NWListener(using: params, on: port)
            listener.newConnectionHandler = { [weak self] connection in
                self?.handleConnection(connection)
            }
            // NWListener.start() does NOT throw on bind failure — the error
            // ("Address already in use" when a stale instance still holds the
            // port) arrives asynchronously here. Without this handler the
            // server silently isn't listening while claiming it started, so
            // host reload triggers hit the stale instance and the live app
            // never reloads. Detect it, tear down, and retry a bounded number
            // of times so a transient hold resolves on its own.
            listener.stateUpdateHandler = { [weak self] state in
                guard let self = self else { return }
                switch state {
                case .ready:
                    self.retryCount = 0
                    print("🔥 Hot reload server listening on port \(self.port.rawValue)")
                case .failed(let error):
                    print("❌ Hot reload server bind failed: \(error)")
                    self.listener?.cancel()
                    self.listener = nil
                    if self.retryCount < self.maxRetries {
                        self.retryCount += 1
                        print("🔁 Retrying hot reload server bind (\(self.retryCount)/\(self.maxRetries)) in 1s…")
                        self.queue.asyncAfter(deadline: .now() + 1) { self.start() }
                    } else {
                        print("⛔️ Hot reload server gave up binding port \(self.port.rawValue) — is another app instance still running?")
                    }
                default:
                    break
                }
            }

            self.listener = listener
            listener.start(queue: queue)
        } catch {
            print("❌ Failed to start hot reload server: \(error)")
        }
    }
    
    func stop() {
        listener?.cancel()
        listener = nil
        print("🔥 Hot reload server stopped")
    }
    
    private func handleConnection(_ connection: NWConnection) {
        connection.start(queue: queue)
        
        // Any connection triggers a reload
        DispatchQueue.main.async {
            NotificationCenter.default.post(name: .reloadWebViewNotification, object: nil)
        }
        
        // Immediately close the connection
        connection.cancel()
        print("🔄 Hot reload triggered")
    }
}

