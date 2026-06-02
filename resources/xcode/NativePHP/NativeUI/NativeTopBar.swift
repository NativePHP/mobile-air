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
            context.coordinator.actionUrls.removeAll()

            for action in actions {
                switch action.data {
                case .action(let actionData):
                    let image = !actionData.icon.isEmpty ? UIImage(systemName: getIconForName(actionData.icon)) : nil
                    let childActions = actionData.children ?? []

                    if !childActions.isEmpty {
                        if #available(iOS 26.0, *) {
                            let menuElements = buildMenuElements(from: childActions, context: context)

                            if !menuElements.isEmpty {
                                let menu = UIMenu(title: "", children: menuElements)
                                let button = UIBarButtonItem(
                                    title: actionData.label,
                                    image: image,
                                    primaryAction: nil,
                                    menu: menu
                                )
                                button.accessibilityLabel = actionData.label
                                button.accessibilityIdentifier = actionData.id
                                barButtonItems.append(button)
                            }

                            continue
                        }
                    }

                    guard let actionUrl = actionData.url, !actionUrl.isEmpty else {
                        continue
                    }

                    // Create button with both image and title when available
                    let button = UIBarButtonItem(
                        title: actionData.label,
                        image: image,
                        target: context.coordinator,
                        action: #selector(Coordinator.actionTapped(_:))
                    )

                    button.accessibilityLabel = actionData.label
                    button.accessibilityIdentifier = actionData.id

                    // Store the URL in the button's tag by storing it in coordinator
                    context.coordinator.actionUrls[actionData.id] = actionUrl
                    barButtonItems.append(button)
                default:
                    continue
                }
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

        func navigate(to url: String) {
            onNavigate(url)
        }
    }
}

private func buildMenuElements(
    from components: [TopBarActionComponent],
    context: NativeTopBar.Context
) -> [UIMenuElement] {
    var elements: [UIMenuElement] = []

    for component in components {
        switch component.data {
        case .divider:
            if let separator = menuSeparatorElement() {
                elements.append(separator)
            }
        case .section(let section):
            let sectionElements = buildMenuElements(from: section.children ?? [], context: context)
            if !sectionElements.isEmpty {
                let sectionMenu = UIMenu(title: section.title, options: .displayInline, children: sectionElements)
                elements.append(sectionMenu)
            }
        case .action(let action):
            guard let url = action.url, !url.isEmpty else {
                continue
            }

            let image = !action.icon.isEmpty ? UIImage(systemName: getIconForName(action.icon)) : nil
            var attributes: UIMenuElement.Attributes = []
            if action.role == "destructive" {
                attributes.insert(.destructive)
            }

            let menuAction = UIAction(
                title: action.label,
                image: image,
                identifier: nil,
                discoverabilityTitle: nil,
                attributes: attributes,
                state: .off
            ) { _ in
                context.coordinator.navigate(to: url)
            }

            if #available(iOS 15.0, *),
               let subtitle = action.subtitle,
               !subtitle.isEmpty {
                menuAction.subtitle = subtitle
            }

            elements.append(menuAction)
        default:
            continue
        }
    }

    return elements
}

private func menuSeparatorElement() -> UIMenuElement? {
    let separatorSelector = NSSelectorFromString("separator")

    guard let menuElementType = NSClassFromString("UIMenuElement") as? NSObject.Type,
          menuElementType.responds(to: separatorSelector),
          let result = menuElementType.perform(separatorSelector)?.takeUnretainedValue() as? UIMenuElement else {
        return nil
    }

    return result
}
