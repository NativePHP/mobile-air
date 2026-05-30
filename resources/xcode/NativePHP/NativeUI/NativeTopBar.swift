import SwiftUI
import UIKit

/// iOS-style Top Navigation Bar using native UINavigationBar
struct NativeTopBar: UIViewRepresentable {
    @ObservedObject var uiState = NativeUIState.shared
    let onNavigate: (String) -> Void

    func makeUIView(context: Context) -> UINavigationBar {
        let navigationBar = UINavigationBar()

        // Configure appearance
        let appearance = UINavigationBarAppearance()
        appearance.configureWithOpaqueBackground()

        navigationBar.standardAppearance = appearance
        navigationBar.scrollEdgeAppearance = appearance
        navigationBar.compactAppearance = appearance

        // Create navigation item
        let navItem = UINavigationItem()
        navigationBar.items = [navItem]

        // Set coordinator as delegate
        navigationBar.delegate = context.coordinator

        // Ensure layout margins respect safe area for button positioning
        // The bar background will extend full width, but buttons will be inset
        if #available(iOS 11.0, *) {
            navigationBar.insetsLayoutMarginsFromSafeArea = true
        }

        return navigationBar
    }

    func updateUIView(_ navigationBar: UINavigationBar, context: Context) {
        guard let topBarData = uiState.topBarData,
              let navItem = navigationBar.items?.first else { return }

        // Update title
        if let subtitle = topBarData.subtitle {
            // Create attributed title with subtitle
            let titleLabel = UILabel()
            titleLabel.numberOfLines = 2
            titleLabel.textAlignment = .center

            let titleText = NSMutableAttributedString()
            let textColor = topBarData.textColor.flatMap { UIColor(hex: $0) } ?? UIColor.label
            titleText.append(NSAttributedString(
                string: topBarData.title + "\n",
                attributes: [
                    .font: UIFont.preferredFont(forTextStyle: .headline),
                    .foregroundColor: textColor
                ]
            ))
            titleText.append(NSAttributedString(
                string: subtitle,
                attributes: [
                    .font: UIFont.preferredFont(forTextStyle: .subheadline),
                    .foregroundColor: textColor.withAlphaComponent(0.7)
                ]
            ))

            titleLabel.attributedText = titleText
            titleLabel.sizeToFit()
            navItem.titleView = titleLabel
        } else {
            navItem.titleView = nil
            navItem.title = topBarData.title
        }

        // Update left bar button (navigation icon)
        if topBarData.showNavigationIcon == true && uiState.hasSideNav() {
            let button = UIBarButtonItem(
                image: UIImage(systemName: "line.3.horizontal"),
                style: .plain,
                target: context.coordinator,
                action: #selector(Coordinator.menuTapped)
            )
            navItem.leftBarButtonItem = button
        } else {
            navItem.leftBarButtonItem = nil
        }

        // Update right bar buttons (actions)
        if let actions = topBarData.children, !actions.isEmpty {
            var barButtonItems: [UIBarButtonItem] = []

            for action in actions {
                let image = !action.data.icon.isEmpty ? UIImage(systemName: getIconForName(action.data.icon)) : nil
                let childActions = action.data.children ?? []

                if !childActions.isEmpty {
                    if #available(iOS 14.0, *) {
                        let menuItems: [UIAction] = childActions.compactMap { child in
                            guard let childUrl = child.data.url, !childUrl.isEmpty else {
                                return nil
                            }

                            let childImage = !child.data.icon.isEmpty ? UIImage(systemName: getIconForName(child.data.icon)) : nil

                            return UIAction(title: child.data.label, image: childImage) { _ in
                                context.coordinator.navigate(to: childUrl)
                            }
                        }

                        if !menuItems.isEmpty {
                            let menu = UIMenu(title: "", children: menuItems)
                            let button = UIBarButtonItem(
                                title: action.data.label,
                                image: image,
                                primaryAction: nil,
                                menu: menu
                            )
                            button.accessibilityLabel = action.data.label
                            button.accessibilityIdentifier = action.data.id
                            barButtonItems.append(button)
                        }

                        continue
                    }
                }

                guard let actionUrl = action.data.url, !actionUrl.isEmpty else {
                    continue
                }

                // Create button with both image and title when available
                let button = TopBarButtonItem(
                    title: action.data.label,
                    image: image,
                    target: context.coordinator,
                    action: #selector(Coordinator.actionTapped(_:))
                )

                button.accessibilityLabel = action.data.label
                button.accessibilityIdentifier = action.data.id
                button.actionUrl = actionUrl
                barButtonItems.append(button)
            }

            navItem.rightBarButtonItems = barButtonItems
        } else {
            navItem.rightBarButtonItems = nil
        }

        // Update appearance with custom colors
        let appearance = UINavigationBarAppearance()

        // iOS 26+: Use transparent background for modern blur effect
        if #available(iOS 26.0, *) {
            appearance.configureWithDefaultBackground()
        } else {
            appearance.configureWithOpaqueBackground()
        }

        if let bgColorHex = topBarData.backgroundColor,
           let bgColor = UIColor(hex: bgColorHex) {
            appearance.backgroundColor = bgColor
        }

        if let textColorHex = topBarData.textColor,
           let textColor = UIColor(hex: textColorHex) {
            appearance.titleTextAttributes = [.foregroundColor: textColor]
            appearance.largeTitleTextAttributes = [.foregroundColor: textColor]
            // Also set the button tint color to match
            navigationBar.tintColor = textColor
        } else {
            // Reset to default if no color specified
            navigationBar.tintColor = nil
        }

        // Apply appearance
        navigationBar.standardAppearance = appearance
        navigationBar.scrollEdgeAppearance = appearance
        navigationBar.compactAppearance = appearance
    }

    func makeCoordinator() -> Coordinator {
        Coordinator(uiState: uiState, onNavigate: onNavigate)
    }

    class Coordinator: NSObject, UINavigationBarDelegate {
        let uiState: NativeUIState
        let onNavigate: (String) -> Void

        init(uiState: NativeUIState, onNavigate: @escaping (String) -> Void) {
            self.uiState = uiState
            self.onNavigate = onNavigate
        }

        @objc func menuTapped() {
            withAnimation(.easeInOut(duration: 0.3)) {
                uiState.openSidebar()
            }
        }

        @objc func actionTapped(_ sender: UIBarButtonItem) {
                        guard let button = sender as? TopBarButtonItem,
                                    let url = button.actionUrl else {
                return
            }

            // Navigate to the URL using the proper navigation callback
            onNavigate(url)
        }

        func navigate(to url: String) {
            onNavigate(url)
        }
    }

    final class TopBarButtonItem: UIBarButtonItem {
        var actionUrl: String?
    }
}
