import SwiftUI

/// Dynamic Side Navigation using slide-in drawer with swipe gesture
struct NativeSideNavigation<Content: View>: View {
    @ObservedObject var uiState = NativeUIState.shared
    @State private var expandedGroups: Set<String> = []
    @State private var dragOffset: CGFloat = 0

    let content: Content
    let onNavigate: (String) -> Void

    // Drawer dimensions
    private let drawerWidthRatio: CGFloat = 0.85 // 85% of screen width
    private let edgeSwipeThreshold: CGFloat = 30

    init(onNavigate: @escaping (String) -> Void, @ViewBuilder content: () -> Content) {
        self.onNavigate = onNavigate
        self.content = content()
    }

    var body: some View {
        GeometryReader { geometry in
            // Use smaller drawer width in landscape mode
            let isLandscape = geometry.size.width > geometry.size.height
            let drawerWidthMultiplier = isLandscape ? 0.4 : drawerWidthRatio
            let drawerWidth = geometry.size.width * drawerWidthMultiplier

            // Computed drawer X offset based on state and drag
            // Add extra offset to account for safe area and ensure complete hiding
            let drawerXOffset: CGFloat = {
                let safeAreaOffset = geometry.safeAreaInsets.leading
                let baseOffset = uiState.shouldPresentSidebar ? 0 : -(drawerWidth + safeAreaOffset + 10)
                return baseOffset + dragOffset
            }()

            // Overlay opacity based on drawer position
            let overlayOpacity: Double = {
                let progress = 1 - abs(drawerXOffset) / drawerWidth
                return Double(max(0, min(0.5, progress * 0.5)))
            }()

            // Edge swipe gesture to open drawer
            let edgeSwipeGesture = DragGesture(minimumDistance: 10)
                .onChanged { value in
                    // Only open if swiping right from the very left edge (0 to threshold)
                    if value.translation.width > 0 && value.startLocation.x < edgeSwipeThreshold {
                        let safeAreaOffset = geometry.safeAreaInsets.leading
                        let maxOffset = drawerWidth + safeAreaOffset + 10
                        // Clamp to not go beyond fully open (0), but don't stop the gesture
                        dragOffset = min(value.translation.width, maxOffset)
                    }
                }
                .onEnded { value in
                    // Open if swiped far enough or with enough velocity
                    let threshold = drawerWidth * 0.3
                    let velocity = value.predictedEndTranslation.width - value.translation.width

                    if value.translation.width > threshold || velocity > 300 {
                        withAnimation(.easeOut(duration: 0.25)) {
                            uiState.openSidebar()
                            dragOffset = 0
                        }
                    } else {
                        withAnimation(.easeOut(duration: 0.25)) {
                            dragOffset = 0
                        }
                    }
                }

            // Drawer drag gesture to close
            let drawerDragGesture = DragGesture()
                .onChanged { value in
                    // Only allow dragging left to close
                    if value.translation.width < 0 {
                        let safeAreaOffset = geometry.safeAreaInsets.leading
                        let maxDrag = drawerWidth + safeAreaOffset + 10
                        // Clamp to not go beyond fully closed, but don't stop the gesture
                        dragOffset = max(value.translation.width, -maxDrag)
                    }
                }
                .onEnded { value in
                    // Close if dragged far enough or with enough velocity
                    let threshold = drawerWidth * 0.3
                    let velocity = value.predictedEndTranslation.width - value.translation.width

                    if abs(value.translation.width) > threshold || velocity < -300 {
                        withAnimation(.easeOut(duration: 0.25)) {
                            uiState.closeSidebar()
                            dragOffset = 0
                        }
                    } else {
                        withAnimation(.easeOut(duration: 0.25)) {
                            dragOffset = 0
                        }
                    }
                }

            ZStack(alignment: .leading) {
                // Main content
                content
                    .zIndex(0)
                    .disabled(uiState.shouldPresentSidebar)

                // Dimmed overlay when drawer is open or being dragged
                if uiState.shouldPresentSidebar || dragOffset != 0 {
                    Color.black
                        .opacity(overlayOpacity)
                        .ignoresSafeArea()
                        .zIndex(1)
                        .onTapGesture {
                            withAnimation(.easeInOut(duration: 0.3)) {
                                uiState.closeSidebar()
                                dragOffset = 0
                            }
                        }
                        .gesture(
                            DragGesture()
                                .onChanged { value in
                                    // Allow closing by swiping left on overlay
                                    if value.translation.width < 0 {
                                        let safeAreaOffset = geometry.safeAreaInsets.leading
                                        let maxDrag = drawerWidth + safeAreaOffset + 10
                                        // Clamp to not go beyond fully closed, but don't stop the gesture
                                        dragOffset = max(value.translation.width, -maxDrag)
                                    }
                                }
                                .onEnded { value in
                                    let threshold = drawerWidth * 0.3
                                    let velocity = value.predictedEndTranslation.width - value.translation.width

                                    if abs(value.translation.width) > threshold || velocity < -300 {
                                        withAnimation(.easeOut(duration: 0.25)) {
                                            uiState.closeSidebar()
                                            dragOffset = 0
                                        }
                                    } else {
                                        withAnimation(.easeOut(duration: 0.25)) {
                                            dragOffset = 0
                                        }
                                    }
                                }
                        )
                        .transition(.opacity)
                }

                // Side drawer
                if uiState.hasSideNav() {
                    drawerContent
                        .frame(width: drawerWidth)
                        .background(Color(.systemBackground))
                        .offset(x: drawerXOffset)
                        .zIndex(2)
                        .gesture(drawerDragGesture)
                        .onAppear {
                            print("📱 NativeSideNavigation: Side nav available")
                            // Initialize expanded groups from data
                            if let children = uiState.sideNavData?.children {
                                for child in children where child.type == "side_nav_group" {
                                    if case .group(let group) = child.data,
                                       group.expanded == true {
                                        expandedGroups.insert(group.heading)
                                    }
                                }
                            }
                        }
                }

                // Invisible edge detector for swipe-to-open
                if uiState.hasSideNav() && !uiState.shouldPresentSidebar {
                    Color.clear
                        .frame(width: edgeSwipeThreshold)
                        .contentShape(Rectangle())
                        .gesture(edgeSwipeGesture)
                        .ignoresSafeArea(edges: .leading)
                        .zIndex(3)
                }
            }
            .onChange(of: uiState.shouldPresentSidebar) { _, newValue in
                if newValue {
                    withAnimation(.easeInOut(duration: 0.3)) {
                        dragOffset = 0
                    }
                }
            }
        }
    }

    // Drawer content
    @ViewBuilder
    private var drawerContent: some View {
        if let sideNavData = uiState.sideNavData,
           let children = sideNavData.children {
            // Separate pinned headers from scrollable content
            let pinnedHeaders = children.filter { child in
                if child.type == "side_nav_header",
                   case .header(let header) = child.data {
                    return header.pinned == true
                }
                return false
            }
            let scrollableChildren = children.filter { child in
                if child.type == "side_nav_header",
                   case .header(let header) = child.data {
                    return header.pinned != true
                }
                return true
            }

            VStack(spacing: 0) {
                // Pinned headers at the top (non-scrollable)
                ForEach(Array(pinnedHeaders.enumerated()), id: \.offset) { index, child in
                    sideNavChild(child: child)
                }

                // Scrollable content
                ScrollView {
                    VStack(alignment: .leading, spacing: 0) {
                        ForEach(Array(scrollableChildren.enumerated()), id: \.offset) { index, child in
                            sideNavChild(child: child)
                        }
                    }
                    .padding(.vertical, 8)
                }
            }
        }
    }

    @ViewBuilder
    private func sideNavChild(child: SideNavChild) -> some View {
        switch child.type {
        case "side_nav_header":
            if case .header(let header) = child.data {
                SideNavHeaderView(header: header)
            }

        case "side_nav_item":
            if case .item(let item) = child.data {
                SideNavItemView(
                    item: item,
                    labelVisibility: uiState.sideNavData?.labelVisibility,
                    onNavigate: { url in
                        onNavigate(url)
                        withAnimation(.easeInOut(duration: 0.3)) {
                            uiState.closeSidebar()
                        }
                    }
                )
            }

        case "side_nav_group":
            if case .group(let group) = child.data {
                SideNavGroupView(
                    group: group,
                    isExpanded: expandedGroups.contains(group.heading),
                    onToggle: {
                        withAnimation(.easeInOut(duration: 0.2)) {
                            if expandedGroups.contains(group.heading) {
                                expandedGroups.remove(group.heading)
                            } else {
                                expandedGroups.insert(group.heading)
                            }
                        }
                    },
                    labelVisibility: uiState.sideNavData?.labelVisibility,
                    onNavigate: { url in
                        onNavigate(url)
                        withAnimation(.easeInOut(duration: 0.3)) {
                            uiState.closeSidebar()
                        }
                    }
                )
            }

        case "horizontal_divider":
            Divider()
                .padding(.vertical, 8)

        default:
            EmptyView()
        }
    }
}

/// Side nav header view
struct SideNavHeaderView: View {
    let header: SideNavHeader

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .top, spacing: 16) {
                // Icon
                if let iconName = header.icon {
                    Image(systemName: getIconForName(iconName))
                        .font(.system(size: 40))
                        .foregroundColor(.accentColor)
                }

                // Title and subtitle
                VStack(alignment: .leading, spacing: 4) {
                    if let title = header.title {
                        Text(title)
                            .font(.headline)
                    }
                    if let subtitle = header.subtitle {
                        Text(subtitle)
                            .font(.subheadline)
                            .foregroundColor(.secondary)
                    }
                }

                Spacer()
            }
            .padding(16)
        }
        .background(parseBackgroundColor(header.backgroundColor))
        .cornerRadius(12)
        .padding(.horizontal, 16)
        .padding(.vertical, 8)
    }

    private func parseBackgroundColor(_ colorString: String?) -> Color {
        guard let colorString = colorString else { return Color(.systemGray6) }
        // Simple hex color parsing
        let hex = colorString.replacingOccurrences(of: "#", with: "")
        guard hex.count == 6 || hex.count == 8 else { return Color(.systemGray6) }

        var rgb: UInt64 = 0
        Scanner(string: hex).scanHexInt64(&rgb)

        let r = Double((rgb >> 16) & 0xFF) / 255.0
        let g = Double((rgb >> 8) & 0xFF) / 255.0
        let b = Double(rgb & 0xFF) / 255.0

        return Color(red: r, green: g, blue: b)
    }
}

/// Single side nav item
struct SideNavItemView: View {
    let item: SideNavItem
    let labelVisibility: String?
    let onNavigate: (String) -> Void
    var leadingIndent: CGFloat = 0

    var body: some View {
        Button(action: {
            print("🖱️ Side nav item clicked: \(item.label) -> \(item.url)")
            handleNavigation()
        }) {
            HStack(spacing: 16) {
                Image(systemName: getIconForName(item.icon))
                    .font(.system(size: 20))
                    .foregroundColor(item.active == true ? .accentColor : .primary)
                    .frame(width: 24)

                if shouldShowLabel() {
                    Text(item.label)
                        .foregroundColor(item.active == true ? .accentColor : .primary)
                }

                Spacer()

                // Badge
                if let badge = item.badge {
                    Text(badge)
                        .font(.caption2)
                        .fontWeight(.semibold)
                        .foregroundColor(.white)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(parseBadgeColor(item.badgeColor))
                        .cornerRadius(12)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.leading, 16 + leadingIndent)
            .padding(.trailing, 16)
            .padding(.vertical, 12)
            .contentShape(Rectangle())
            .background(item.active == true ? Color.accentColor.opacity(0.1) : Color.clear)
        }
        .buttonStyle(.plain)
    }

    private func shouldShowLabel() -> Bool {
        switch labelVisibility {
        case "unlabeled": return false
        case "selected": return item.active == true
        default: return true
        }
    }

    private func handleNavigation() {
        // Check if should open in browser
        if item.openInBrowser == true || isExternalUrl(item.url) {
            print("🌐 Opening external URL: \(item.url)")
            if let url = URL(string: item.url) {
                UIApplication.shared.open(url)
            }
        } else {
            print("📱 Opening internal URL: \(item.url)")
            onNavigate(item.url)
        }
    }

    private func isExternalUrl(_ url: String) -> Bool {
        return (url.hasPrefix("http://") || url.hasPrefix("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
    }

    private func parseBadgeColor(_ colorString: String?) -> Color {
        switch colorString?.lowercased() {
        case "lime": return Color(red: 0.52, green: 0.8, blue: 0.09)
        case "green": return Color(red: 0.13, green: 0.77, blue: 0.37)
        case "blue": return Color(red: 0.23, green: 0.51, blue: 0.96)
        case "red": return Color(red: 0.94, green: 0.27, blue: 0.27)
        case "yellow": return Color(red: 0.92, green: 0.70, blue: 0.03)
        case "purple": return Color(red: 0.66, green: 0.33, blue: 0.97)
        case "pink": return Color(red: 0.93, green: 0.28, blue: 0.60)
        case "orange": return Color(red: 0.98, green: 0.45, blue: 0.09)
        default: return Color(red: 0.39, green: 0.40, blue: 0.95) // Indigo
        }
    }
}

/// Expandable group of items
struct SideNavGroupView: View {
    let group: SideNavGroup
    let isExpanded: Bool
    let onToggle: () -> Void
    let labelVisibility: String?
    let onNavigate: (String) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Group header
            Button(action: onToggle) {
                HStack(spacing: 16) {
                    if let iconName = group.icon {
                        Image(systemName: getIconForName(iconName))
                            .font(.system(size: 20))
                            .frame(width: 24)
                    }

                    Text(group.heading)
                        .fontWeight(.medium)

                    Spacer()

                    Image(systemName: "chevron.right")
                        .font(.system(size: 12, weight: .semibold))
                        .foregroundColor(.secondary)
                        .rotationEffect(.degrees(isExpanded ? 90 : 0))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .contentShape(Rectangle())
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            }
            .buttonStyle(.plain)

            // Children (animated)
            if isExpanded, let children = group.children {
                VStack(alignment: .leading, spacing: 0) {
                    ForEach(Array(children.enumerated()), id: \.offset) { index, child in
                        if let item = child.data {
                            SideNavItemView(
                                item: item,
                                labelVisibility: labelVisibility,
                                onNavigate: onNavigate,
                                leadingIndent: 24
                            )
                            .transition(.asymmetric(
                                insertion: .move(edge: .top).combined(with: .opacity),
                                removal: .move(edge: .top).combined(with: .opacity)
                            ))
                        }
                    }
                }
            }
        }
    }
}
