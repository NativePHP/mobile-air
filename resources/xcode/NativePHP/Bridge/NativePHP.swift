final class LaravelBridge {
    static let shared = LaravelBridge()

    var send: ((_ event: String, _ payload: [String: Any?]) -> Void)?
}
