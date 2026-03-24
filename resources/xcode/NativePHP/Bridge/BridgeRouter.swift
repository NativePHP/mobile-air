import Foundation

// MARK: - Bridge Error Types

enum BridgeError: Error {
    case functionNotFound(String)
    case invalidParameters(String)
    case executionFailed(String)
    case permissionDenied(String)
    case permissionRequired(String)
    case unknownError(String)

    var errorCode: String {
        switch self {
        case .functionNotFound: return "FUNCTION_NOT_FOUND"
        case .invalidParameters: return "INVALID_PARAMETERS"
        case .executionFailed: return "EXECUTION_FAILED"
        case .permissionDenied: return "PERMISSION_DENIED"
        case .permissionRequired: return "PERMISSION_REQUIRED"
        case .unknownError: return "UNKNOWN_ERROR"
        }
    }

    var message: String {
        switch self {
        case .functionNotFound(let name):
            return "Function '\(name)' not found in bridge registry"
        case .invalidParameters(let details):
            return "Invalid parameters: \(details)"
        case .executionFailed(let details):
            return "Function execution failed: \(details)"
        case .permissionDenied(let details):
            return "Permission denied: \(details)"
        case .permissionRequired(let details):
            return "Permission required: \(details)"
        case .unknownError(let details):
            return "Unknown error: \(details)"
        }
    }
}

// MARK: - Bridge Function Protocol

protocol BridgeFunction {
    /// Execute the function with the given parameters
    /// - Parameter parameters: Dictionary of parameters passed from PHP
    /// - Returns: Dictionary of data to return to PHP
    /// - Throws: BridgeError if execution fails
    func execute(parameters: [String: Any]) throws -> [String: Any]
}

// MARK: - Bridge Function Registry

final class BridgeFunctionRegistry {
    static let shared = BridgeFunctionRegistry()

    private var functions: [String: BridgeFunction] = [:]
    private let lock = NSLock()

    private init() {
        // Registry is initialized empty - functions register themselves
    }

    /// Register a function with the bridge
    /// - Parameters:
    ///   - name: The fully qualified name (e.g., "Location.Get", "Camera.TakePhoto")
    ///   - function: The function implementation conforming to BridgeFunction
    func register(_ name: String, function: BridgeFunction) {
        lock.lock()
        defer { lock.unlock() }

        functions[name] = function
        print("🔌 Registered bridge function: \(name)")
    }

    /// Check if a function exists in the registry
    /// - Parameter name: The fully qualified function name
    /// - Returns: True if the function exists, false otherwise
    func exists(_ name: String) -> Bool {
        lock.lock()
        defer { lock.unlock() }

        return functions[name] != nil
    }

    /// Get a function from the registry
    /// - Parameter name: The fully qualified function name
    /// - Returns: The function implementation, or nil if not found
    func get(_ name: String) -> BridgeFunction? {
        lock.lock()
        defer { lock.unlock() }

        return functions[name]
    }

    /// Get all registered function names (useful for debugging)
    func getAllFunctionNames() -> [String] {
        lock.lock()
        defer { lock.unlock() }

        return Array(functions.keys).sorted()
    }
}

// MARK: - Bridge Response Builder

struct BridgeResponse {
    /// Build a success response
    /// Returns function data directly without wrapping
    static func success(data: [String: Any] = [:]) -> [String: Any] {
        return data
    }

    /// Build an error response
    static func error(code: String, message: String, data: [String: Any] = [:]) -> [String: Any] {
        return [
            "status": "error",
            "code": code,
            "message": message,
            "data": data
        ]
    }

    /// Build an error response from a BridgeError
    static func error(from error: BridgeError, data: [String: Any] = [:]) -> [String: Any] {
        return self.error(code: error.errorCode, message: error.message, data: data)
    }

    /// Convert response dictionary to JSON string
    static func toJSON(_ response: [String: Any]) -> String? {
        do {
            let jsonData = try JSONSerialization.data(withJSONObject: response, options: [])
            return String(data: jsonData, encoding: .utf8)
        } catch {
            print("❌ Failed to serialize bridge response: \(error)")
            return nil
        }
    }
}

// MARK: - C Bridge Functions

/// Check if a native function exists in the bridge registry
/// - Parameter functionName: The fully qualified function name (e.g., "Location.Get")
/// - Returns: 1 if function exists, 0 if it doesn't
@_cdecl("NativePHPCan")
public func NativePHPCan(_ cFunctionName: UnsafePointer<CChar>?) -> Int32 {
    guard let cFunctionName = cFunctionName else {
        return 0
    }

    let functionName = String(cString: cFunctionName)
    let exists = BridgeFunctionRegistry.shared.exists(functionName)

    print("🔍 NativePHPCan('\(functionName)'): \(exists ? "YES" : "NO")")

    return exists ? 1 : 0
}

/// Call a native function through the bridge router
/// - Parameters:
///   - functionName: The fully qualified function name (e.g., "Location.Get")
///   - parametersJSON: JSON string containing function parameters
/// - Returns: JSON string with result: {"status": "success"|"error", "data": {...}, "code": "...", "message": "..."}
///           Returns NULL if function doesn't exist (check with NativePHPCan first)
@_cdecl("NativePHPCall")
public func NativePHPCall(
    _ cFunctionName: UnsafePointer<CChar>?,
    _ cParametersJSON: UnsafePointer<CChar>?
) -> UnsafePointer<CChar>? {
    // Validate function name
    guard let cFunctionName = cFunctionName else {
        print("❌ NativePHPCall: Missing function name")
        return nil
    }

    let functionName = String(cString: cFunctionName)

    // Check if function exists - return nil if not
    guard BridgeFunctionRegistry.shared.exists(functionName) else {
        print("❌ NativePHPCall: Function '\(functionName)' not found")
        return nil
    }

    // Parse parameters JSON
    var parameters: [String: Any] = [:]
    if let cParametersJSON = cParametersJSON {
        let parametersJSON = String(cString: cParametersJSON)

        if !parametersJSON.isEmpty {
            do {
                if let jsonData = parametersJSON.data(using: .utf8),
                   let parsed = try JSONSerialization.jsonObject(with: jsonData) as? [String: Any] {
                    parameters = parsed
                }
            } catch {
                let response = BridgeResponse.error(
                    code: "INVALID_JSON",
                    message: "Failed to parse parameters JSON: \(error.localizedDescription)"
                )
                if let jsonString = BridgeResponse.toJSON(response) {
                    return UnsafePointer(strdup(jsonString))
                }
                return nil
            }
        }
    }

    print("🚀 NativePHPCall('\(functionName)') with parameters: \(parameters)")

    // Get the function
    guard let function = BridgeFunctionRegistry.shared.get(functionName) else {
        // This shouldn't happen since we checked exists() above, but handle it anyway
        print("❌ NativePHPCall: Function '\(functionName)' disappeared between checks")
        return nil
    }

    // Execute the function with error handling
    do {
        let result = try function.execute(parameters: parameters)
        let response = BridgeResponse.success(data: result)

        if let jsonString = BridgeResponse.toJSON(response) {
            print("✅ NativePHPCall('\(functionName)') succeeded")
            return UnsafePointer(strdup(jsonString))
        } else {
            let errorResponse = BridgeResponse.error(
                code: "SERIALIZATION_ERROR",
                message: "Failed to serialize response to JSON"
            )
            if let errorJson = BridgeResponse.toJSON(errorResponse) {
                return UnsafePointer(strdup(errorJson))
            }
            return nil
        }
    } catch let error as BridgeError {
        // Handle known bridge errors
        print("⚠️ NativePHPCall('\(functionName)') failed: \(error.message)")
        let response = BridgeResponse.error(from: error)

        if let jsonString = BridgeResponse.toJSON(response) {
            return UnsafePointer(strdup(jsonString))
        }
        return nil
    } catch {
        // Handle unexpected errors
        print("❌ NativePHPCall('\(functionName)') unexpected error: \(error.localizedDescription)")
        let response = BridgeResponse.error(
            code: "UNKNOWN_ERROR",
            message: "Unexpected error: \(error.localizedDescription)"
        )

        if let jsonString = BridgeResponse.toJSON(response) {
            return UnsafePointer(strdup(jsonString))
        }
        return nil
    }
}

// MARK: - NativeUI Bridge Functions

/// Called by PHP's nativephp_ui_init() to register the shared memory region
/// used for native UI rendering. The region pointer can be stored for later use.
@_cdecl("NativeUI_RegisterRegion")
public func NativeUI_RegisterRegion(_ region: UnsafeMutableRawPointer?) {
    guard let region = region else {
        print("⚠️ NativeUI_RegisterRegion called with nil region")
        return
    }
    print("🖼️ NativeUI_RegisterRegion: region at \(region)")
    // TODO: Store region pointer for native UI rendering
}

/// Called by PHP's nativephp_ui_shutdown() to unregister the shared memory region.
@_cdecl("NativeUI_UnregisterRegion")
public func NativeUI_UnregisterRegion() {
    print("🖼️ NativeUI_UnregisterRegion: region released")
    // TODO: Clear stored region pointer
}

// MARK: - NativeElement Bridge Functions (stubs)

/// Called by nphp_element_init() to register the element shared memory region.
/// Stub — full implementation lives on element-yoga branch.
@_cdecl("NativeElement_RegisterRegion")
public func NativeElement_RegisterRegion(_ region: UnsafeMutableRawPointer?) {
    guard let region = region else {
        print("NativeElement_RegisterRegion called with nil region")
        return
    }
    print("NativeElement_RegisterRegion: region at \(region)")
}

/// Called by nphp_element_shutdown() to unregister the element region.
@_cdecl("NativeElement_UnregisterRegion")
public func NativeElement_UnregisterRegion() {
    print("NativeElement_UnregisterRegion: region released")
}

/// Called by nphp_element_publish() / nphp_frame_end() after flat buffer write.
@_cdecl("NativeElement_PostTreeUpdate")
public func NativeElement_PostTreeUpdate() {
    // No-op on v3.1 — element rendering not yet available
}