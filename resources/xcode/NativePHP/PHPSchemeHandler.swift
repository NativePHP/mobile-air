import WebKit

class PHPSchemeHandler: NSObject, WKURLSchemeHandler {
    let domain = "127.0.0.1"

    /// Shared serial queue for ALL PHP execution across the app.
    /// PHP is single-threaded - concurrent execution causes crashes.
    /// Both PHPSchemeHandler (WebView requests) and NativeQueueCoordinator (queue jobs) must use this.
    static let phpSerialQueue: DispatchQueue = {
        let appName = Bundle.main.infoDictionary?["CFBundleName"] as? String ?? "NativePHP"
        return DispatchQueue(label: "com.NativePHP.\(appName).phpSerialQueue")
    }()

    private let maxRedirects = 10
    private var activeTasks: [ObjectIdentifier: WKURLSchemeTask] = [:]
    private let taskLock = NSLock()

    // This method is called when the web view starts loading a request with your custom scheme
    func webView(_ webView: WKWebView, start schemeTask: WKURLSchemeTask) {
        taskLock.lock()
        activeTasks[ObjectIdentifier(schemeTask)] = schemeTask
        taskLock.unlock()
        startLoading(for: schemeTask)
    }

    // This method is called if the web view stops loading the request
    func webView(_ webView: WKWebView, stop schemeTask: WKURLSchemeTask) {
        taskLock.lock()
        activeTasks.removeValue(forKey: ObjectIdentifier(schemeTask))
        taskLock.unlock()
        stopLoading(for: schemeTask)
    }

    // This method is called when a request with the custom scheme is made
    func startLoading(for schemeTask: WKURLSchemeTask) {
        guard let request = schemeTask.request as URLRequest?,
              let url = request.url else {
            let error = error(code: 400, description: "Invalid request")
            if isTaskActive(schemeTask) {
                schemeTask.didFailWithError(error)
                removeTask(schemeTask)
            }
            return
        }

        // Extract request data
        extractRequestData(from: request) { [weak self] result in
            guard let self = self, self.isTaskActive(schemeTask) else { return }

            switch result {
            case .success(let requestData):
                let pathComponents = url.pathComponents

                if let assetsIndex = pathComponents.firstIndex(of: "_assets") {
                    // Gather everything after "_assets":
                    let subComponents = pathComponents[(assetsIndex + 1)...]

                    // Join them back together: "build/app.js"
                    let relativeAssetPath = subComponents.joined(separator: "/")

                    // Attempt to find this file in Documents/app/public
                    let appPath = AppUpdateManager.shared.getAppPath()
                    let localPath = appPath + "/public/" + relativeAssetPath

                    if FileManager.default.fileExists(atPath: localPath) {

                        do {
                            let fileURL = URL(fileURLWithPath: localPath)
                            let fileAttributes = try FileManager.default.attributesOfItem(atPath: localPath)
                            let fileSize = fileAttributes[.size] as? Int64 ?? 0

                            let mimeType = self.guessMimeType(for: relativeAssetPath)

                            // Check if this is a range request
                            let rangeHeader = requestData.headers["Range"] ?? requestData.headers["range"]

                            if let rangeHeader = rangeHeader, rangeHeader.hasPrefix("bytes=") {
                                // Handle byte-range request for streaming
                                let rangeString = rangeHeader.replacingOccurrences(of: "bytes=", with: "")
                                let rangeParts = rangeString.split(separator: "-")

                                if rangeParts.count == 2 {
                                    let start = Int64(rangeParts[0]) ?? 0
                                    let end = rangeParts[1].isEmpty ? fileSize - 1 : (Int64(rangeParts[1]) ?? fileSize - 1)
                                    let length = end - start + 1

                                    // Read only the requested byte range
                                    guard let fileHandle = FileHandle(forReadingAtPath: localPath) else {
                                        throw NSError(domain: "PHPSchemeHandler", code: 500, userInfo: [NSLocalizedDescriptionKey: "Could not open file"])
                                    }

                                    if #available(iOS 13.0, *) {
                                        try fileHandle.seek(toOffset: UInt64(start))
                                        let data = fileHandle.readData(ofLength: Int(length))
                                        try fileHandle.close()

                                        let headers: [String: String] = [
                                            "Content-Type": mimeType,
                                            "Content-Length": "\(data.count)",
                                            "Content-Range": "bytes \(start)-\(end)/\(fileSize)",
                                            "Accept-Ranges": "bytes"
                                        ]

                                        let response = HTTPURLResponse(url: url,
                                                                       statusCode: 206, // Partial Content
                                                                       httpVersion: "HTTP/1.1",
                                                                       headerFields: headers)

                                        if self.isTaskActive(schemeTask) {
                                            schemeTask.didReceive(response!)
                                            schemeTask.didReceive(data)
                                            schemeTask.didFinish()
                                            self.removeTask(schemeTask)
                                        }
                                    }

                                    return
                                }
                            }

                            // For large files (>10MB), use streaming via InputStream
                            // For small files, load into memory for better performance
                            if fileSize > 10_000_000 {
                                // Large file - stream it in 1MB chunks (local disk is fast)
                                guard let inputStream = InputStream(url: fileURL) else {
                                    throw NSError(domain: "PHPSchemeHandler", code: 500, userInfo: [NSLocalizedDescriptionKey: "Could not create input stream"])
                                }

                                let headers: [String: String] = [
                                    "Content-Type": mimeType,
                                    "Content-Length": "\(fileSize)",
                                    "Accept-Ranges": "bytes"
                                ]

                                let response = HTTPURLResponse(url: url,
                                                               statusCode: 200,
                                                               httpVersion: "HTTP/1.1",
                                                               headerFields: headers)

                                if self.isTaskActive(schemeTask) {
                                    schemeTask.didReceive(response!)

                                    // Stream file in 1MB chunks (local disk, no network latency)
                                    inputStream.open()
                                    let bufferSize = 1024 * 1024 // 1MB chunks
                                    let buffer = UnsafeMutablePointer<UInt8>.allocate(capacity: bufferSize)
                                    defer {
                                        buffer.deallocate()
                                        inputStream.close()
                                    }

                                    while inputStream.hasBytesAvailable && self.isTaskActive(schemeTask) {
                                        let bytesRead = inputStream.read(buffer, maxLength: bufferSize)
                                        if bytesRead > 0 {
                                            let data = Data(bytes: buffer, count: bytesRead)
                                            schemeTask.didReceive(data)
                                        } else if bytesRead < 0 {
                                            // Error occurred
                                            break
                                        }
                                    }

                                    if self.isTaskActive(schemeTask) {
                                        schemeTask.didFinish()
                                        self.removeTask(schemeTask)
                                    }
                                }
                            } else {
                                // Small file - load into memory for best performance
                                let fileData = try Data(contentsOf: fileURL)

                                let headers: [String: String] = [
                                    "Content-Type": mimeType,
                                    "Content-Length": "\(fileData.count)",
                                    "Accept-Ranges": "bytes"
                                ]

                                let response = HTTPURLResponse(url: url,
                                                               statusCode: 200,
                                                               httpVersion: "HTTP/1.1",
                                                               headerFields: headers)

                                if self.isTaskActive(schemeTask) {
                                    schemeTask.didReceive(response!)
                                    schemeTask.didReceive(fileData)
                                    schemeTask.didFinish()
                                    self.removeTask(schemeTask)
                                }
                            }

                            return
                        } catch {
                            // Just fall back to PHP
                            print("⚠️ Error serving asset: \(error.localizedDescription)")
                        }
                    }
                }

                WebView.dataStore.httpCookieStore.getAllCookies { cookies in
                    guard self.isTaskActive(schemeTask) else { return }

                    var request = requestData

                    let domainCookies = cookies.filter { $0.domain == "127.0.0.1" }

                    var csrfToken: String = "";

                    // Build "Cookie" header
                    let cookieHeader = domainCookies.map {
                        if ($0.name == "XSRF-TOKEN") {
                            csrfToken = $0.value.removingPercentEncoding ?? ""
                        }

                        return "\($0.name)=\($0.value.removingPercentEncoding ?? "")"
                    }.joined(separator: "; ")

                    request.headers["Cookie"] = cookieHeader
                    request.headers["X-XSRF-TOKEN"] = csrfToken

                    self.forwardToPHP(requestData: request, schemeTask: schemeTask, redirectCount: 0)
                }

            case .failure(let error):
                // Pass the extraction error back to the scheme task
                if self.isTaskActive(schemeTask) {
                    schemeTask.didFailWithError(error)
                    self.removeTask(schemeTask)
                }
            }
        }
    }

    func stopLoading(for schemeTask: WKURLSchemeTask) {
        // Cancel any ongoing operations for this task
        print("Canceling scheme task: \(schemeTask)")
    }

    private func isTaskActive(_ schemeTask: WKURLSchemeTask) -> Bool {
        taskLock.lock()
        let isActive = activeTasks[ObjectIdentifier(schemeTask)] != nil
        taskLock.unlock()
        return isActive
    }

    private func removeTask(_ schemeTask: WKURLSchemeTask) {
        taskLock.lock()
        activeTasks.removeValue(forKey: ObjectIdentifier(schemeTask))
        taskLock.unlock()
    }

    private func guessMimeType(for fileName: String) -> String {
        let pathExtension = (fileName as NSString).pathExtension.lowercased()
        switch pathExtension {
        case "html", "htm":
            return "text/html"
        case "css":
            return "text/css"
        case "js":
            return "application/javascript"
        case "png":
            return "image/png"
        case "jpg", "jpeg":
            return "image/jpeg"
        case "gif":
            return "image/gif"
        case "svg":
            return "image/svg+xml"
        case "m4a":
            return "audio/mp4"
        default:
            return "application/octet-stream"
        }
    }

    // Helper method to extract request data
    private func extractRequestData(from request: URLRequest,
                                    completion: @escaping (Result<RequestData, Error>) -> Void) {
        guard request.url?.host == domain else {
            // If the domain doesn't match, don't do anything
            print("⚠ Domain doesn't match expected!")
            print(request.url?.host ?? "")
            return
        }

        // Extract GET parameters
        var query: String?
        if let url = request.url {
            let urlComponents = URLComponents(url: url, resolvingAgainstBaseURL: false)
            query = urlComponents?.query
        }

        // Extract HTTP method
        let method = request.httpMethod ?? "GET"

        // Extract Headers
        let headers = request.allHTTPHeaderFields ?? [:]

        // Extract POST data if method is POST/PUT/PATCH
        var data: String?
        if ["POST", "PUT", "PATCH"].contains(method.uppercased()), let httpBody = request.httpBody {
            if let body = String(data: httpBody, encoding: .utf8) {
                data = body
            }
        }

        // Define the URI
        let uri = request.url?.path ?? "/"

        // Create a RequestData object
        let requestData = RequestData(
            method: method,
            uri: uri,
            data: data ?? nil,
            query: query ?? "",
            headers: headers
        )

        // Pass the extracted data back via completion
        completion(.success(requestData))
    }

    private func parseSetCookieHeader(cookieString: String) -> [HTTPCookiePropertyKey: Any] {
        var properties: [HTTPCookiePropertyKey: Any] = [:]

        // Split the cookie string into components separated by ';'
        let components = cookieString.split(separator: ";")

        // The first component is "name=value"
        if let nameValue = components.first {
            let nv = nameValue.split(separator: "=", maxSplits: 1)
            if nv.count == 2 {
                let name = String(nv[0])
                let value = String(nv[1])
                properties[.name] = name
                properties[.value] = value
            }
        }

        // The remaining components are attributes
        for attribute in components.dropFirst() {
            let attr = attribute.trimmingCharacters(in: .whitespacesAndNewlines)
            let pair = attr.split(separator: "=", maxSplits: 1)
            if pair.count == 2 {
                let key = String(pair[0]).lowercased()
                let value = String(pair[1])
                switch key {
                case "path":
                    properties[.path] = value
                case "domain":
                    properties[.domain] = value
                case "expires":
                    let dateFormatter = DateFormatter()
                    dateFormatter.locale = Locale(identifier: "en_US_POSIX")
                    dateFormatter.dateFormat = "E, d MMM yyyy HH:mm:ss z"
                    if let date = dateFormatter.date(from: value) {
                        properties[.expires] = date
                    }
                case "httponly":
                    if #available(iOS 18.2, *) {
                        properties[.setByJavaScript] = false
                    } else {
                        // Fallback on earlier versions
                    }
                case "secure":
                    properties[.secure] = true
                default:
                    break
                }
            } else {
                // Attributes like 'HttpOnly' or 'Secure' without value
                let key = String(pair[0]).lowercased()
                if key == "httponly" {
                    if #available(iOS 18.2, *) {
                        properties[.setByJavaScript] = false
                    } else {
                        // Fallback on earlier versions
                    }
                } else if key == "secure" {
                    properties[.secure] = true
                }
            }
        }

        // Set the domain and path if not already set
        if properties[.domain] == nil {
            properties[.domain] = domain
        }

        if properties[.path] == nil {
            properties[.path] = "/"
        }

        return properties
    }

    private func error(code: Int, description: String) -> NSError
    {
        print("ERROR: \(description)")
        return NSError(domain: "PHPAppSchemeHandler", code: code, userInfo: [NSLocalizedDescriptionKey: description])
    }

    private func forwardToPHP(requestData: RequestData, schemeTask: WKURLSchemeTask, redirectCount: Int = 0) {
        getResponse(request: requestData) { result in
            guard self.isTaskActive(schemeTask) else { return }

            switch result {
            case .success(let responseData):
                // Parse the response data into headers and body
                guard let responseString = String(data: responseData, encoding: .utf8) else {
                    let error = self.error(code: 500, description: "Failed to decode response")
                    if self.isTaskActive(schemeTask) {
                        schemeTask.didFailWithError(error)
                    }
                    return
                }

                // Split headers and body
                print("Processing response...")
                let components = responseString.components(separatedBy: "\r\n\r\n")
                guard components.count >= 2 else {
                    // Send the error as a response to the WebView
                    guard let httpResponse = HTTPURLResponse(url: URL(string: requestData.uri)!,
                                                             statusCode: 500,
                                                             httpVersion: "HTTP/1.1",
                                                             headerFields: [
                                                                "Content-Type": "text/html",
                                                                "Content-Length": "\(components[0].lengthOfBytes(using: .utf8))"
                                                             ]) else {
                        let error = self.error(code: 500, description: "Failed to create HTTP response")
                        if self.isTaskActive(schemeTask) {
                            schemeTask.didFailWithError(error)
                        }
                        return
                    }

                    if self.isTaskActive(schemeTask) {
                        schemeTask.didReceive(httpResponse)

                        if let data = components[0].data(using: .utf8) {
                            schemeTask.didReceive(data)
                        }

                        _ = self.error(code: 500, description: "Invalid PHP Response Format")
                        schemeTask.didFinish()
                        self.removeTask(schemeTask)
                    }

                    return
                }

                let headerString = components[0]
                let bodyString = components[1]

                // Parse headers into a dictionary (case-insensitive keys)
                var headers: [String: String] = [:]
                let headerLines = headerString.components(separatedBy: "\r\n")

                // First, parse status code
                var statusCode = 200
                if let statusLine = headerLines.first,
                   let codeString = statusLine.components(separatedBy: " ").dropFirst(1).first,
                   let code = Int(codeString) {
                    statusCode = code
                }

                for (index, line) in headerLines.enumerated() {
                    // First one is status, which we already parsed
                    if index == 0 {
                        continue
                    }
                    let headerComponents = line.components(separatedBy: ": ")
                    if headerComponents.count == 2 {
                        // Store with lowercase key for case-insensitive lookup
                        headers[headerComponents[0].lowercased()] = headerComponents[1]
                    }
                }

                // Legacy X-Native-UI header support (now using Edge.Set bridge function instead)
                // Note: This is kept for backward compatibility but the primary method is now
                // via PHP's Edge::set() which calls nativephp_call('Edge.Set', ...)
                // We no longer clear UI state here because Edge components are managed via the bridge
                let contentType = headers["content-type"] ?? ""
                let isHtmlResponse = contentType.contains("text/html")
                let isJsonResponse = contentType.contains("application/json")
                let isSuccessResponse = (200...299).contains(statusCode)

                if (isHtmlResponse || isJsonResponse) && isSuccessResponse {
                    if let nativeUIJson = headers["x-native-ui"] {
                        // Legacy header-based update (still supported for backward compatibility)
                        DispatchQueue.main.async {
                            NativeUIState.shared.updateFromJson(nativeUIJson)
                        }
                    }
                    // Removed: else branch that cleared UI state on HTML responses without header
                    // This was causing EDGE components set via Edge::set() to be immediately cleared
                }


                var request = requestData
                if let location = headers["location"] {
                    request.uri = location.trimmingCharacters(in: .whitespaces)
                    request.method = "GET"

                    // Fix root URL redirects: ensure php://127.0.0.1 has trailing slash
                    if request.uri == "php://127.0.0.1" {
                        request.uri = "php://127.0.0.1/"
                    }

                    // Perform an external redirect to the webview, not trying to pass the location to PHP again
                    if !request.uri.hasPrefix("http://") && !request.uri.hasPrefix("php://") {
                        let trimmedLocation = location.trimmingCharacters(in: .whitespaces)
                        let absoluteURL: String

                        if trimmedLocation.hasPrefix("/") {
                            // Convert relative path to absolute URL
                            absoluteURL = "php://127.0.0.1\(trimmedLocation)"
                        } else {
                            // Already absolute or relative without leading slash
                            absoluteURL = trimmedLocation
                        }

                        NotificationCenter.default.post(name: .redirectToURLNotification, object: nil, userInfo: ["url": absoluteURL])
                        return
                    }

                    WebView.dataStore.httpCookieStore.getAllCookies { cookies in
                        guard self.isTaskActive(schemeTask) else { return }

                        let domainCookies = cookies.filter { $0.domain == "127.0.0.1" }

                        // Build "Cookie" header
                        let cookieHeader = domainCookies.map {
                            return "\($0.name)=\($0.value.removingPercentEncoding ?? "")"
                        }.joined(separator: "; ")

                        request.headers["Cookie"] = cookieHeader

                        let newRedirectCount = redirectCount + 1

                        if newRedirectCount > self.maxRedirects {
                            let error = self.error(code: 500, description: "Too Many Redirects")
                            if self.isTaskActive(schemeTask) {
                                schemeTask.didFailWithError(error)
                                self.removeTask(schemeTask)
                            }
                            return
                        }

                        self.forwardToPHP(requestData: request, schemeTask: schemeTask, redirectCount: newRedirectCount)
                    }

                    return
                }

                print("Forwarding response to WebView")

                guard let httpResponse = HTTPURLResponse(url: (URL(string: requestData.uri) ?? URL(string: "/"))!,
                                                        statusCode: statusCode,
                                                        httpVersion: "HTTP/1.1",
                                                        headerFields: headers) else {
                    let error = self.error(code: 500, description: "Failed to create HTTP response")
                    if self.isTaskActive(schemeTask) {
                        schemeTask.didFailWithError(error)
                    }
                    return
                }

                // Send the response to the task
                if self.isTaskActive(schemeTask) {
                    schemeTask.didReceive(httpResponse)

                    // Send the body data
                    if let bodyData = bodyString.data(using: .utf8) {
                        schemeTask.didReceive(bodyData)
                    } else {
                        let error = self.error(code: 500, description: "Failed to encode body data")
                        schemeTask.didFailWithError(error)
                        return
                    }

                    // Indicate that the task has finished
                    schemeTask.didFinish()
                    self.removeTask(schemeTask)
                    print("Done")
                }

            case .failure(let error):
                // Handle failure by sending the error to the task
                if self.isTaskActive(schemeTask) {
                    schemeTask.didFailWithError(error)
                    self.removeTask(schemeTask)
                }
            }
        }
    }

    private func getResponse(request: RequestData,
                              completion: @escaping (Result<Data, Error>) -> Void) {
        Self.phpSerialQueue.async {
            print()
            print("\(request.method) \(request.uri)")
            print()
            print(request.headers.map { "\($0.key)=\($0.value)" }.joined(separator: "\n"))

            // Pass the request to Laravel and get Laravel's response
            let response = NativePHPApp.laravel(request: request) ?? "No response from Laravel"

            // Extract cookie headers
            let components = response.components(separatedBy: "\r\n\r\n")
            let headers = components[0]

            let headersList = headers.components(separatedBy: "\n").filter { !$0.isEmpty }

            let setCookieHeaders = headersList.filter { $0.hasPrefix("Set-Cookie:") }

            DispatchQueue.main.async {
                for header in setCookieHeaders {
                    // Remove "Set-Cookie: " prefix
                    let cookieString = header.replacingOccurrences(of: "Set-Cookie: ", with: "")
                        .trimmingCharacters(in: .whitespacesAndNewlines)
                        .replacingOccurrences(of: ";\\s+", with: ";", options: .regularExpression)

                    // Create HTTPCookie from the cookieString
                    if let cookie = HTTPCookie(properties: self.parseSetCookieHeader(cookieString: cookieString)) {
                        // Set the cookie in WKHTTPCookieStore
                        WebView.dataStore.httpCookieStore.setCookie(cookie)
                    }
                }

                // Convert the response to Data
                if let responseData = response.data(using: .utf8) {
                    completion(.success(responseData))
                } else {
                    let encodingError = self.error(code: 500, description: "Failed to encode PHP response")
                    completion(.failure(encodingError))
                }
            }
        }
    }
}

struct RequestData {
    var method: String
    var uri: String
    var data: String?
    var query: String?
    var headers: [String: String]
}
