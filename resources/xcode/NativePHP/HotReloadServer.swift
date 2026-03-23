import Foundation
import Network

class HotReloadServer {
    private var listener: NWListener?
    private let port: NWEndpoint.Port = 9999
    private let queue = DispatchQueue(label: "HotReloadServer")
    
    static let shared = HotReloadServer()
    
    private init() {}
    
    func start() {
        guard listener == nil else { return }
        
        do {
            listener = try NWListener(using: .tcp, on: port)
            listener?.newConnectionHandler = { [weak self] connection in
                self?.handleConnection(connection)
            }
            
            listener?.start(queue: queue)
            print("🔥 Hot reload server started on port \(port)")
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

        print("🔥 Hot reload connection received")

        // Reboot the persistent runtime on a background thread (blocks until done)
        DispatchQueue.global(qos: .userInitiated).async {
            if PersistentPHPRuntime.shared.isBooted {
                print("🔄 Rebooting persistent runtime...")
                let success = PersistentPHPRuntime.shared.reboot()
                print("🔄 Persistent runtime reboot: \(success ? "success" : "failed")")
            } else {
                print("🔄 Persistent runtime not booted, skipping reboot")
            }

            // Then trigger WebView reload on main thread
            DispatchQueue.main.async {
                NotificationCenter.default.post(name: .reloadWebViewNotification, object: nil)
                print("🔄 WebView reload notification posted")
            }
        }

        // Immediately close the connection
        connection.cancel()
    }
}

