import Foundation
import SwiftUI

/// Singleton to manage NativeUI state from Laravel responses
class NativeUIState: ObservableObject {
    static let shared = NativeUIState()

    @Published var components: [NativeComponent] = []
    @Published var bottomNavData: BottomNavData?
    @Published var sideNavData: SideNavData?
    @Published var topBarData: TopBarData?

    // Cache to prevent unnecessary updates
    private var lastJsonString: String?

    // Sidebar presentation control (for JavaScript access)
    @Published var shouldPresentSidebar = false

    private init() {}

    /// Open the sidebar (can be called from JavaScript)
    func openSidebar() {
        shouldPresentSidebar = true
    }

    /// Close the sidebar
    func closeSidebar() {
        shouldPresentSidebar = false
    }

    /// Update UI state from Edge component JSON
    func updateFromJson(_ json: String?) {
        guard let json = json, !json.isEmpty else {
            print("❌ NativeUIState: No Native UI data in response (json is nil or empty)")
            clearAll()
            return
        }

        // Skip parsing if JSON hasn't changed
        if lastJsonString == json {
            print("⏭️ NativeUIState: JSON unchanged, skipping update")
            return
        }

        lastJsonString = json

        print("✅ NativeUIState: Parsing \(json.count) characters of JSON")
        let parsedComponents = NativeUIParser.parse(json)
        print("✅ NativeUIState: Parsed \(parsedComponents.count) component(s)")

        components = parsedComponents

        // Extract bottom nav if present
        let bottomNav = parsedComponents.first { $0.type == "bottom_nav" }

        if let bottomNav = bottomNav,
           let bottomNavData = NativeUIParser.parseBottomNavData(from: bottomNav) {
            print("✅ NativeUIState: Set bottomNavData with \(bottomNavData.children?.count ?? 0) items")
            self.bottomNavData = bottomNavData
        } else {
            print("⚠️ NativeUIState: No bottom_nav component found")
            self.bottomNavData = nil
        }

        // Extract side nav if present
        let sideNav = parsedComponents.first { $0.type == "side_nav" }

        if let sideNav = sideNav,
           let sideNavData = NativeUIParser.parseSideNavData(from: sideNav) {
            self.sideNavData = sideNavData
        } else {
            self.sideNavData = nil
        }

        // Extract top bar if present
        let topBar = parsedComponents.first { $0.type == "top_bar" }

        if let topBar = topBar,
           let topBarData = NativeUIParser.parseTopBarData(from: topBar) {
            print("✅ NativeUIState: Set topBarData with title: \(topBarData.title ?? "nil")")
            self.topBarData = topBarData
        } else {
            print("⚠️ NativeUIState: No top_bar component found")
            self.topBarData = nil
        }
    }

    /// Clear all UI state
    func clearAll() {
        print("🧹 NativeUIState: Clearing all UI state")
        lastJsonString = nil
        components = []
        bottomNavData = nil
        sideNavData = nil
        topBarData = nil
    }

    /// Check if bottom nav should be visible
    func hasBottomNav() -> Bool {
        return bottomNavData != nil && !(bottomNavData?.children?.isEmpty ?? true)
    }

    /// Check if side nav should be visible
    func hasSideNav() -> Bool {
        return sideNavData != nil && !(sideNavData?.children?.isEmpty ?? true)
    }

    /// Check if top bar should be visible
    func hasTopBar() -> Bool {
        return topBarData != nil
    }
}
