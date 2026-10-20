import CoreFoundation
import Foundation
import SwiftUI

public enum NativeTabRole: String, Sendable {
    case navigation
    case search
    case action
}

func nativeTabPresentationIdentifier(sourceIdentifier: String, index: Int) -> String {
    "\(sourceIdentifier)#\(index)"
}

func nativeTabPresentationIsHidden(explicitlyHidden: Bool, hasPushedDestination: Bool) -> Bool {
    explicitlyHidden || hasPushedDestination
}

public struct NativeTabDescriptor: Identifiable, Sendable {
    public let id: String
    public let label: String
    public let accessibilityLabel: String
    public let icon: String
    public let badge: String?
    public let showsNews: Bool
    public let role: NativeTabRole

    public init(id: String, label: String, accessibilityLabel: String, icon: String,
                badge: String?, showsNews: Bool, role: NativeTabRole) {
        self.id = id
        self.label = label
        self.accessibilityLabel = accessibilityLabel
        self.icon = icon
        self.badge = badge
        self.showsNews = showsNews
        self.role = role
    }
}

public struct NativeTabPresentationConfiguration {
    private let values: [String: Any]

    public init?(json: String) {
        guard json.utf8.count <= 16_384,
              let data = json.data(using: .utf8),
              let object = try? JSONSerialization.jsonObject(with: data),
              let values = object as? [String: Any],
              Self.depth(of: values) <= 8 else { return nil }
        self.values = values
    }

    private static func depth(of value: Any) -> Int {
        if let object = value as? [String: Any] {
            return 1 + (object.values.map(depth).max() ?? 0)
        }
        if let array = value as? [Any] {
            return 1 + (array.map(depth).max() ?? 0)
        }
        return 0
    }

    public func string(_ key: String) -> String? { values[key] as? String }
    public func bool(_ key: String) -> Bool? { values[key] as? Bool }
    public func number(_ key: String) -> Double? {
        guard let number = values[key] as? NSNumber,
              CFGetTypeID(number) != CFBooleanGetTypeID() else { return nil }
        return number.doubleValue
    }
    public func strings(_ key: String) -> [String]? { values[key] as? [String] }
    public func object(_ key: String) -> [String: Any]? { values[key] as? [String: Any] }
}

public struct NativeTabPresentationContext {
    public let tabs: [NativeTabDescriptor]
    public let selectedTabIdentifier: String
    public let configuration: NativeTabPresentationConfiguration
    public let isHidden: Bool
    private let activation: (String) -> Void

    public init(tabs: [NativeTabDescriptor], selectedTabIdentifier: String,
                configuration: NativeTabPresentationConfiguration, isHidden: Bool,
                activate: @escaping (String) -> Void) {
        self.tabs = tabs
        self.selectedTabIdentifier = selectedTabIdentifier
        self.configuration = configuration
        self.isHidden = isHidden
        self.activation = activate
    }

    public func activate(_ tabIdentifier: String) {
        guard tabs.contains(where: { $0.id == tabIdentifier }) else { return }
        activation(tabIdentifier)
    }
}

public typealias NativeTabPresenter = (NativeTabPresentationContext) -> AnyView

public final class NativeTabPresentationRegistry {
    public static let shared = NativeTabPresentationRegistry()

    private var presenters: [String: NativeTabPresenter] = [:]
    private let lock = NSLock()

    private init() {}

    /// Registers a presenter. The first registration for an identifier wins.
    @discardableResult
    public func register(identifier: String, presenter: @escaping NativeTabPresenter) -> Bool {
        lock.lock()
        defer { lock.unlock() }
        guard presenters[identifier] == nil else {
            Self.diagnostic("Duplicate tab presenter '\(identifier)' ignored; first registration wins.")
            return false
        }
        presenters[identifier] = presenter
        return true
    }

    func presenter(for identifier: String) -> NativeTabPresenter? {
        lock.lock()
        defer { lock.unlock() }
        return presenters[identifier]
    }

    static func diagnostic(_ message: String) {
#if DEBUG
        print("[NativePHP] \(message)")
#endif
    }

    func resetForTesting() {
        lock.lock()
        presenters.removeAll()
        lock.unlock()
    }
}
