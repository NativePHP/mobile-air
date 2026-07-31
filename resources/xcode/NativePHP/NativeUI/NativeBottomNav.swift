import SwiftUI
import UIKit

struct NativeUITabBar: UIViewRepresentable {
    let items: [BottomNavItemComponent]
    let labelVisibility: String?
    let activeColor: String?
    @Binding var selectedTab: String
    let onTabSelected: (String) -> Void

    func makeUIView(context: Context) -> UITabBar {
        let tabBar = UITabBar()

        // Configure appearance
        let appearance = UITabBarAppearance()
        appearance.configureWithDefaultBackground()

        // Ensure background extends all the way
        appearance.backgroundColor = UIColor.systemBackground

        // Remove shadow/border
        appearance.shadowColor = nil
        appearance.shadowImage = nil

        tabBar.standardAppearance = appearance
        if #available(iOS 15.0, *) {
            tabBar.scrollEdgeAppearance = appearance
        }

        // Apply custom active color (tint color for selected items)
        if let activeColorHex = activeColor, let color = UIColor(hex: activeColorHex) {
            tabBar.tintColor = color
        }

        // Don't add extra margins from safe area - we handle positioning via SwiftUI
        tabBar.insetsLayoutMarginsFromSafeArea = false

        // Set up tab bar items
        tabBar.items = items.enumerated().map { (index, item) in
            // Determine title based on label visibility mode
            let title: String? = {
                switch labelVisibility {
                case "unlabeled":
                    return nil
                case "selected":
                    // For "selected" mode, only show label if this item is active
                    return item.data.active == true ? item.data.label : nil
                default:
                    // "labeled" or nil - always show labels
                    return item.data.label
                }
            }()

            let tabItem = UITabBarItem(
                title: title,
                image: UIImage(systemName: getIconForName(item.data.icon)),
                tag: index
            )

            // Set badge value - priority: badge text > news dot > nil
            if let badge = item.data.badge {
                tabItem.badgeValue = badge
            } else if item.data.news == true {
                // Show empty dot for news indicator
                tabItem.badgeValue = " "
            } else {
                tabItem.badgeValue = nil
            }

            // Set badge color if provided
            if let badgeColorHex = item.data.badgeColor {
                tabItem.badgeColor = UIColor(hex: badgeColorHex)
            }

            return tabItem
        }

        // Set delegate before setting initial selection to catch any events
        tabBar.delegate = context.coordinator

        // Set initial selected item (suppress delegate callback)
        context.coordinator.isProgrammaticUpdate = true
        if selectedTab.isEmpty {
            tabBar.selectedItem = nil
        } else if let initialIndex = items.firstIndex(where: { $0.data.id == selectedTab }) {
            tabBar.selectedItem = tabBar.items?[initialIndex]
        }

        context.coordinator.isProgrammaticUpdate = false

        return tabBar
    }

    func updateUIView(_ tabBar: UITabBar, context: Context) {
        // Apply custom active color (ensure it persists across updates)
        if let activeColorHex = activeColor, let color = UIColor(hex: activeColorHex) {
            tabBar.tintColor = color
        }

        // Check if items have changed (count or content)
        let currentItemCount = tabBar.items?.count ?? 0
        let itemsChanged = currentItemCount != items.count
        let labelVisibilityChanged = context.coordinator.labelVisibility != labelVisibility

        // Always keep coordinator's labelVisibility in sync
        context.coordinator.labelVisibility = labelVisibility

        if itemsChanged {
            // Rebuild tab bar items
            tabBar.items = items.enumerated().map { (index, item) in
                // Determine title based on label visibility mode
                let title: String? = {
                    switch labelVisibility {
                    case "unlabeled":
                        return nil
                    case "selected":
                        // For "selected" mode, only show label if this item is active
                        return item.data.active == true ? item.data.label : nil
                    default:
                        // "labeled" or nil - always show labels
                        return item.data.label
                    }
                }()

                let tabItem = UITabBarItem(
                    title: title,
                    image: UIImage(systemName: getIconForName(item.data.icon)),
                    tag: index
                )

                // Set badge value - priority: badge text > news dot > nil
                if let badge = item.data.badge {
                    tabItem.badgeValue = badge
                } else if item.data.news == true {
                    // Show empty dot for news indicator
                    tabItem.badgeValue = " "
                } else {
                    tabItem.badgeValue = nil
                }

                // Set badge color if provided
                if let badgeColorHex = item.data.badgeColor {
                    tabItem.badgeColor = UIColor(hex: badgeColorHex)
                }

                return tabItem
            }

            // Update coordinator's items reference
            context.coordinator.items = items
        } else {
            // Items count hasn't changed, but badge values, labels, or label visibility might have
            // Update badges and titles on existing items
            let currentSelectedIndex = items.firstIndex(where: { $0.data.id == selectedTab })

            for (index, item) in items.enumerated() {
                if let tabItem = tabBar.items?[index] {
                    // Always update title - it may have changed even if visibility mode hasn't
                    let title: String? = {
                        switch labelVisibility {
                        case "unlabeled":
                            return nil
                        case "selected":
                            // Use current selection state, not data's active property
                            return (currentSelectedIndex == index) ? item.data.label : nil
                        default:
                            return item.data.label
                        }
                    }()
                    tabItem.title = title

                    // Update icon in case it changed
                    tabItem.image = UIImage(systemName: getIconForName(item.data.icon))

                    // Set badge value - priority: badge text > news dot > nil
                    if let badge = item.data.badge {
                        tabItem.badgeValue = badge
                    } else if item.data.news == true {
                        // Show empty dot for news indicator
                        tabItem.badgeValue = " "
                    } else {
                        tabItem.badgeValue = nil
                    }

                    if let badgeColorHex = item.data.badgeColor {
                        tabItem.badgeColor = UIColor(hex: badgeColorHex)
                    }
                }
            }

            // Update coordinator's items reference even when count hasn't changed
            context.coordinator.items = items
        }

        // Update selected item when binding changes
        context.coordinator.isProgrammaticUpdate = true
        var selectedIndex: Int? = nil
        if selectedTab.isEmpty {
            tabBar.selectedItem = nil
        } else if let index = items.firstIndex(where: { $0.data.id == selectedTab }) {
            selectedIndex = index
            tabBar.selectedItem = tabBar.items?[index]
        } else {
            tabBar.selectedItem = nil
            // Update the binding to reflect that no tab is selected
            context.coordinator.selectedTab = ""
        }

        // Update labels for "selected" visibility mode
        if labelVisibility == "selected" {
            for (i, tabItem) in (tabBar.items ?? []).enumerated() {
                tabItem.title = (selectedIndex != nil && i == selectedIndex) ? items[i].data.label : nil
            }
        }

        context.coordinator.isProgrammaticUpdate = false
    }

    func makeCoordinator() -> Coordinator {
        Coordinator(items: items, labelVisibility: labelVisibility, selectedTab: $selectedTab, onTabSelected: onTabSelected)
    }

    class Coordinator: NSObject, UITabBarDelegate {
        var items: [BottomNavItemComponent]
        var labelVisibility: String?
        @Binding var selectedTab: String
        let onTabSelected: (String) -> Void
        var isProgrammaticUpdate = false

        init(items: [BottomNavItemComponent], labelVisibility: String?, selectedTab: Binding<String>, onTabSelected: @escaping (String) -> Void) {
            self.items = items
            self.labelVisibility = labelVisibility
            self._selectedTab = selectedTab
            self.onTabSelected = onTabSelected
        }

        func tabBar(_ tabBar: UITabBar, didSelect item: UITabBarItem) {
            // Ignore programmatic updates
            if isProgrammaticUpdate {
                return
            }

            guard let index = tabBar.items?.firstIndex(of: item),
                  index < items.count else { return }

            let selectedItem = items[index]

            // Update labels for "selected" visibility mode
            if labelVisibility == "selected" {
                for (i, tabItem) in (tabBar.items ?? []).enumerated() {
                    tabItem.title = (i == index) ? items[i].data.label : nil
                }
            }

            // Only load URL if we're switching to a different tab
            if selectedTab != selectedItem.data.id {
                selectedTab = selectedItem.data.id
                onTabSelected(selectedItem.data.id)
            }
        }
    }
}

struct NativeBottomNavigation: View {
    @ObservedObject var uiState = NativeUIState.shared
    let onTabSelected: (String) -> Void

    // Track selected tab
    @State private var selectedTab: String = ""

    var body: some View {
        if let bottomNavData = uiState.bottomNavData,
           let items = bottomNavData.children, !items.isEmpty {

            if #available(iOS 26.0, *) {
                // iOS 26+: Tab bar with Liquid Glass effect
                NativeUITabBar(
                    items: items,
                    labelVisibility: bottomNavData.labelVisibility,
                    activeColor: bottomNavData.activeColor,
                    selectedTab: $selectedTab,
                    onTabSelected: { tabId in
                        loadTabURL(tabId: tabId)
                    }
                )
                .frame(height: 49)
                .padding(.bottom, 20)
                .onAppear {
                    ensureSelectedTabInitialized(items: items)
                }
                .onChange(of: uiState.bottomNavData) { _, newData in
                    if let newItems = newData?.children {
                        updateSelectedTab(items: newItems)
                    }
                }
            } else {
                // iOS 18 and below: Tab bar extends to screen bottom
                NativeUITabBar(
                    items: items,
                    labelVisibility: bottomNavData.labelVisibility,
                    activeColor: bottomNavData.activeColor,
                    selectedTab: $selectedTab,
                    onTabSelected: { tabId in
                        loadTabURL(tabId: tabId)
                    }
                )
                .background(Color(.systemBackground))
                .ignoresSafeArea(.all, edges: .bottom)
                .onAppear {
                    ensureSelectedTabInitialized(items: items)
                }
                .onChange(of: uiState.bottomNavData) { _, newData in
                    if let newItems = newData?.children {
                        updateSelectedTab(items: newItems)
                    }
                }
            }
        }
    }

    /// Ensure selectedTab is initialized based on active state (runs synchronously during body evaluation)
    private func ensureSelectedTabInitialized(items: [BottomNavItemComponent]) {
        if selectedTab.isEmpty || !items.contains(where: { $0.data.id == selectedTab }) {
            if let activeItem = items.first(where: { $0.data.active == true }) {
                selectedTab = activeItem.data.id
            } else {
                selectedTab = ""
            }
        }
    }

    /// Update selected tab when data changes (visual state only, no URL load)
    private func updateSelectedTab(items: [BottomNavItemComponent]) {
        if let activeItem = items.first(where: { $0.data.active == true }) {
            if selectedTab != activeItem.data.id {
                selectedTab = activeItem.data.id
            }
        } else {
            // Clear if none selected
            if !selectedTab.isEmpty {
                selectedTab = ""
            }
        }
    }

    /// Load URL for a specific tab by finding the item and passing its raw URL
    private func loadTabURL(tabId: String) {
        guard let bottomNavData = uiState.bottomNavData,
              let items = bottomNavData.children,
              let item = items.first(where: { $0.data.id == tabId }) else {
            print("❌ No item found for tab: \(tabId)")
            return
        }

        onTabSelected(item.data.url)
    }
}
