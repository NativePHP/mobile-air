import SwiftUI
import UIKit

/// iOS-style Top Navigation Bar using native UINavigationBar
struct NativeTopBar: UIViewRepresentable {
    @ObservedObject var uiState = NativeUIState.shared
    let onNavigate: (String) -> Void

    func makeUIView(context: Context) -> UINavigationBar {
        let navigationBar = UINavigationBar()

        // Transparent by default — PHP config can override via backgroundColor
        let appearance = UINavigationBarAppearance()
        appearance.configureWithTransparentBackground()

        navigationBar.standardAppearance = appearance
        navigationBar.scrollEdgeAppearance = appearance
        navigationBar.compactAppearance = appearance
        navigationBar.isTranslucent = true
        navigationBar.backgroundColor = .clear

        // Create navigation item
        let navItem = UINavigationItem()
        navigationBar.items = [navItem]

        // Set coordinator as delegate
        navigationBar.delegate = context.coordinator

        // Ensure layout margins respect safe area for button positioning
        navigationBar.insetsLayoutMarginsFromSafeArea = true

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

                // Create button with both image and title when available
                let button = UIBarButtonItem(
                    title: action.data.label,
                    image: image,
                    target: context.coordinator,
                    action: #selector(Coordinator.actionTapped(_:))
                )

                button.accessibilityLabel = action.data.label
                button.accessibilityIdentifier = action.data.id

                // Store the URL in the button's tag by storing it in coordinator
                context.coordinator.actionUrls[action.data.id] = action.data.url
                barButtonItems.append(button)
            }

            navItem.rightBarButtonItems = barButtonItems
        } else {
            navItem.rightBarButtonItems = nil
        }

        // Update appearance with custom colors
        let appearance = UINavigationBarAppearance()

        if let bgColorHex = topBarData.backgroundColor,
           let bgColor = UIColor(hex: bgColorHex) {
            if bgColor.cgColor.alpha < 1.0 {
                appearance.configureWithTransparentBackground()
            } else {
                appearance.configureWithOpaqueBackground()
            }
            appearance.backgroundColor = bgColor
        } else {
            // Default: transparent
            appearance.configureWithTransparentBackground()
        }

        if let textColorHex = topBarData.textColor,
           let textColor = UIColor(hex: textColorHex) {
            appearance.titleTextAttributes = [.foregroundColor: textColor]
            appearance.largeTitleTextAttributes = [.foregroundColor: textColor]
            navigationBar.tintColor = textColor
        } else {
            navigationBar.tintColor = nil
        }

        // Apply appearance
        navigationBar.standardAppearance = appearance
        navigationBar.scrollEdgeAppearance = appearance
        navigationBar.compactAppearance = appearance
        navigationBar.isTranslucent = true
        navigationBar.backgroundColor = .clear
    }

    func makeCoordinator() -> Coordinator {
        Coordinator(uiState: uiState, onNavigate: onNavigate)
    }

    class Coordinator: NSObject, UINavigationBarDelegate {
        let uiState: NativeUIState
        let onNavigate: (String) -> Void
        var actionUrls: [String: String] = [:]

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
            guard let actionId = sender.accessibilityIdentifier,
                  let url = actionUrls[actionId] else {
                return
            }

            // Navigate to the URL using the proper navigation callback
            onNavigate(url)
        }
    }
}
