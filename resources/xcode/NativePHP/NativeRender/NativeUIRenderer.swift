import SwiftUI

// MARK: - Root Content View

/// Root composable that renders a NativeUITree.
/// Equivalent to Android's NativeUIContent().
struct NativeUIContent: View {
    @ObservedObject var bridge = NativeUIBridge.shared

    var body: some View {
        if let tree = bridge.currentTree {
            RenderNode(node: tree.root)
        }
    }
}

// MARK: - Node Renderer

/// Recursively render a NativeUINode via the renderer registry.
struct RenderNode: View {
    let node: NativeUINode

    var body: some View {
        if let renderer = NativeRendererRegistry.shared.get(node.type) {
            renderer(node)
                .modifier(NodeModifier(node: node))
        } else {
            // Unknown type — render children in a VStack as fallback
            VStack(spacing: 0) {
                ForEach(node.children) { child in
                    RenderNode(node: child)
                }
            }
            .modifier(NodeModifier(node: node))
        }
    }
}

// MARK: - Node Modifier (layout + style)

/// Applies layout and style from a NativeUINode to any view.
/// Equivalent to Android's buildModifier().
struct NodeModifier: ViewModifier {
    let node: NativeUINode

    func body(content: Content) -> some View {
        // Modifier order matching Compose buildModifier() semantics:
        //
        // Compose (outside-in): size → margin → shadow → clip → bg → border → opacity → padding
        //
        // SwiftUI applies inside-out. Key insight: in Compose, padding is the
        // innermost modifier (last applied). In SwiftUI that means apply it first.
        // But .frame(maxWidth: .infinity) after .padding() would cause the padded
        // view to fill WITHIN bounds (padding is inside the fill). However, styling
        // (bg, clip) needs to wrap the padded content, not the frame.
        //
        // Order: content → size → margin → shadow → clip → bg → border → opacity → padding
        // SwiftUI: content → padding → opacity → bg → clip → border → shadow → margin → size
        //
        // HOWEVER: padding before size causes SwiftUI layout issues when greedy
        // views (Color.clear) are involved. So we keep size outermost but use
        // a nested approach for styles.
        var view = AnyView(content)

        // Compose buildModifier (outside-in): size → margin → shadow → clip → bg → border → opacity → padding
        //
        // Key SwiftUI challenge: fixed sizes (w-[40]) must be INSIDE background
        // so bg fills the area. But fill sizes (w-full) must be OUTSIDE everything
        // to avoid padding expanding beyond screen bounds.
        //
        // Solution: apply fixed sizes early, fill sizes late.

        // 1. Inner padding (innermost — content breathing room)
        if let layout = node.layout, hasEdges(layout.paddingTop, layout.paddingRight, layout.paddingBottom, layout.paddingLeft) {
            view = AnyView(view.padding(EdgeInsets(
                top: CGFloat(layout.paddingTop),
                leading: CGFloat(layout.paddingLeft),
                bottom: CGFloat(layout.paddingBottom),
                trailing: CGFloat(layout.paddingRight)
            )))
        }

        // 2. ALL sizing (fixed + fill) — before background so bg fills the area
        if let layout = node.layout {
            view = AnyView(applyFixedSize(view, layout: layout))
            view = AnyView(applyFillSize(view, layout: layout))
        }

        if let style = node.style {
            // 3. Background (fills the sized area)
            let bgColor = Color(argb: style.bgColor)
            if bgColor != Color(argb: 0x00000000) {
                view = AnyView(view.background(bgColor))
            }

            // 4. Corner radius + clipping
            if style.borderRadius > 0 {
                view = AnyView(view.clipShape(RoundedRectangle(cornerRadius: CGFloat(style.borderRadius))))
            }

            // 5. Border (skip for line nodes — their border-* classes control stroke, not decoration)
            if style.borderWidth > 0 && node.type != "line" {
                let shape = RoundedRectangle(cornerRadius: CGFloat(style.borderRadius))
                view = AnyView(view.overlay(shape.stroke(Color(argb: style.borderColor), lineWidth: CGFloat(style.borderWidth))))
            }

            // 6. Shadow / elevation
            if style.elevation > 0 {
                view = AnyView(view.shadow(color: .black.opacity(0.25), radius: CGFloat(style.elevation), x: 0, y: CGFloat(style.elevation / 2)))
            }

            // 7. Opacity
            if style.opacity < 1 {
                view = AnyView(view.opacity(Double(style.opacity)))
            }
        }

        // 8. Click/press handlers (after all styling so the full area is tappable)
        // Matches Android where click handling is part of buildModifier().
        if node.onPress != 0 || node.onLongPress != 0 {
            view = AnyView(view
                .contentShape(Rectangle())
                .applyClickHandlers(node: node)
            )
        }

        // 9. Margin (outer spacing)
        if let layout = node.layout, hasEdges(layout.marginTop, layout.marginRight, layout.marginBottom, layout.marginLeft) {
            view = AnyView(view.padding(EdgeInsets(
                top: CGFloat(layout.marginTop),
                leading: CGFloat(layout.marginLeft),
                bottom: CGFloat(layout.marginBottom),
                trailing: CGFloat(layout.marginRight)
            )))
        }

        // 10. Safe area
        if let layout = node.layout, layout.safeArea != 0 {
            view = AnyView(view.safeAreaPadding(.all))
        }

        return view
    }

    /// Apply fixed dimensions (w-[40], h-[200]) BEFORE background
    /// so the background fills the specified area.
    @ViewBuilder
    private func applyFixedSize<V: View>(_ view: V, layout: NodeLayout) -> some View {
        let w = layout.widthMode
        let h = layout.heightMode

        view.frame(
            width: w == SizeMode.fixed && layout.width > 0 ? CGFloat(layout.width) : nil,
            height: h == SizeMode.fixed && layout.height > 0 ? CGFloat(layout.height) : nil
        )
    }

    /// Apply fill dimensions (w-full, h-full).
    /// Uses FillLayout to hard-constrain width/height to the proposed size,
    /// matching Compose's fillMaxWidth()/fillMaxHeight() behavior.
    /// SwiftUI's .frame(maxWidth: .infinity) only sets a preference — children
    /// can still report wider sizes and expand the parent. FillLayout prevents this.
    @ViewBuilder
    private func applyFillSize<V: View>(_ view: V, layout: NodeLayout) -> some View {
        let w = layout.widthMode
        let h = layout.heightMode

        if w == SizeMode.fill || h == SizeMode.fill {
            // .frame(maxWidth/maxHeight) inside FillLayout makes the child
            // fill the proposed size and centers content (default alignment).
            // FillLayout constrains the REPORTED size to prevent parent expansion.
            FillLayout(fillWidth: w == SizeMode.fill, fillHeight: h == SizeMode.fill) {
                view.frame(
                    maxWidth: w == SizeMode.fill ? .infinity : nil,
                    maxHeight: h == SizeMode.fill ? .infinity : nil
                )
            }
        } else {
            view
        }
    }
}

// MARK: - Fill Layout

/// Custom Layout that constrains children to the parent's proposed size,
/// matching Compose's fillMaxWidth()/fillMaxHeight() behavior.
/// Unlike .frame(maxWidth: .infinity), this prevents children from
/// reporting a wider/taller size than the parent proposed.
struct FillLayout: Layout {
    let fillWidth: Bool
    let fillHeight: Bool

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        guard let child = subviews.first else { return .zero }

        // Propose the parent's size to the child
        let childSize = child.sizeThatFits(proposal)

        // Report the PARENT's proposed size for fill axes,
        // and the CHILD's size for wrap axes
        return CGSize(
            width: fillWidth ? (proposal.width ?? childSize.width) : childSize.width,
            height: fillHeight ? (proposal.height ?? childSize.height) : childSize.height
        )
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        guard let child = subviews.first else { return }

        // Place child with the constrained bounds
        child.place(
            at: bounds.origin,
            proposal: ProposedViewSize(width: bounds.width, height: bounds.height)
        )
    }
}

// MARK: - Container Views

struct RenderColumn: View {
    let node: NativeUINode

    var body: some View {
        let layout = node.layout
        let gap = CGFloat(layout?.gap ?? 0)
        let hAlign = resolveHorizontalAlignment(layout?.alignItems ?? 0)
        let justifyContent = layout?.justifyContent ?? 0

        VStack(alignment: hAlign, spacing: gap) {
            if justifyContent == 1 || justifyContent == 2 || justifyContent == 4 || justifyContent == 5 { // center, end, spaceAround, spaceEvenly
                Spacer(minLength: 0)
            }
            ForEach(Array(node.children.enumerated()), id: \.element.id) { index, child in
                if justifyContent == 3 && index > 0 { // spaceBetween
                    Spacer(minLength: 0)
                }
                if justifyContent == 4 && index > 0 { // spaceAround
                    Spacer(minLength: 0)
                }
                if justifyContent == 5 && index > 0 { // spaceEvenly
                    Spacer(minLength: 0)
                }
                RenderNode(node: child)
                    .maybeFlexGrow(child, axis: .vertical)
            }
            if justifyContent == 1 || justifyContent == 4 || justifyContent == 5 { // center, spaceAround, spaceEvenly
                Spacer(minLength: 0)
            }
        }
    }
}

struct RenderRow: View {
    let node: NativeUINode

    var body: some View {
        let layout = node.layout
        let gap = CGFloat(layout?.gap ?? 0)
        let vAlign = resolveVerticalAlignment(layout?.alignItems ?? 0)
        let justifyContent = layout?.justifyContent ?? 0
        let fillWidth = (layout?.widthMode ?? 0) == SizeMode.fill

        HStack(alignment: vAlign, spacing: gap) {
            if justifyContent == 1 || justifyContent == 2 || justifyContent == 4 || justifyContent == 5 { // center, end, spaceAround, spaceEvenly
                Spacer(minLength: 0)
            }
            ForEach(Array(node.children.enumerated()), id: \.element.id) { index, child in
                if justifyContent == 3 && index > 0 { // spaceBetween
                    Spacer(minLength: 0)
                }
                if justifyContent == 4 && index > 0 { // spaceAround
                    Spacer(minLength: 0)
                }
                if justifyContent == 5 && index > 0 { // spaceEvenly
                    Spacer(minLength: 0)
                }
                RenderNode(node: child)
                    .maybeFlexGrow(child, axis: .horizontal)
            }
            if justifyContent == 1 || justifyContent == 4 || justifyContent == 5 { // center, spaceAround, spaceEvenly
                Spacer(minLength: 0)
            }
            // When fill width, push content to leading edge by adding trailing spacer
            // (only when justifyContent is start and no spacers already present)
            if fillWidth && justifyContent == 0 {
                Spacer(minLength: 0)
            }
        }
    }
}

struct RenderStack: View {
    let node: NativeUINode

    var body: some View {
        ZStack(alignment: .topLeading) {
            ForEach(node.children) { child in
                RenderNode(node: child)
            }
        }
    }
}

struct RenderScrollView: View {
    let node: NativeUINode

    var body: some View {
        let horizontal = node.props.getBool("horizontal")

        if horizontal {
            ScrollView(.horizontal, showsIndicators: node.props.getBool("shows_indicators", default: true)) {
                LazyHStack(spacing: CGFloat(node.layout?.gap ?? 0)) {
                    ForEach(flattenedChildren) { child in
                        RenderNode(node: child)
                    }
                }
                .padding(contentPadding)
            }
        } else {
            ScrollView(.vertical, showsIndicators: node.props.getBool("shows_indicators", default: true)) {
                LazyVStack(alignment: resolveHorizontalAlignment(wrapperAlignItems), spacing: CGFloat(effectiveGap)) {
                    ForEach(flattenedChildren) { child in
                        RenderNode(node: child)
                    }
                }
                .padding(contentPadding)
            }
        }
    }

    // Flatten single wrapper column/row for lazy virtualization
    private var flattenedChildren: [NativeUINode] {
        let horizontal = node.props.getBool("horizontal")
        if node.children.count == 1 {
            let wrapper = node.children[0]
            let isMatch = horizontal ? wrapper.type == "row" : wrapper.type == "column"
            if isMatch && !wrapper.children.isEmpty {
                return wrapper.children
            }
        }
        return node.children
    }

    private var effectiveGap: Float {
        if node.children.count == 1 {
            let wrapper = node.children[0]
            let horizontal = node.props.getBool("horizontal")
            let isMatch = horizontal ? wrapper.type == "row" : wrapper.type == "column"
            if isMatch, let wl = wrapper.layout {
                return wl.gap
            }
        }
        return node.layout?.gap ?? 0
    }

    private var wrapperAlignItems: Int {
        if node.children.count == 1 {
            let wrapper = node.children[0]
            if let wl = wrapper.layout { return wl.alignItems }
        }
        return 0
    }

    private var contentPadding: EdgeInsets {
        if node.children.count == 1 {
            let wrapper = node.children[0]
            if let wl = wrapper.layout, hasEdges(wl.paddingTop, wl.paddingRight, wl.paddingBottom, wl.paddingLeft) {
                return EdgeInsets(
                    top: CGFloat(wl.paddingTop),
                    leading: CGFloat(wl.paddingLeft),
                    bottom: CGFloat(wl.paddingBottom),
                    trailing: CGFloat(wl.paddingRight)
                )
            }
        }
        return EdgeInsets()
    }
}

// MARK: - Leaf Renderers (built into core)

struct RenderButton: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let label = p.getString("label")
        let _ = print("[NativeUI] RenderButton label='\(label)' props=\(p.map)")
        let pressCbId = { let cb = p.getCallbackId("on_press"); return cb != 0 ? cb : node.onPress }()
        let longPressCbId = node.onLongPress
        let disabled = p.getBool("disabled")
        // bg-* class sets style.bgColor; color prop may be text color (e.g. text-white).
        // Use style bgColor if set, otherwise fall back to color prop for button bg.
        let styleBg = node.style?.bgColor ?? 0
        let color = styleBg != 0 ? styleBg : p.getColor("color", default: 0xFF007AFF)
        let labelColor = p.getColor("label_color", default: 0xFFFFFFFF)
        // text-white sets "color" prop — use it for label if label_color not set
        let propColor = p.getColor("color", default: 0)
        let effectiveLabelColor = (p.getColor("label_color", default: 0) != 0)
            ? labelColor
            : (propColor != 0 ? propColor : labelColor)
        let fontSize = p.getFloat("font_size")

        // Custom Box-style button (matches Android's RenderButton which uses
        // a styled Box, not Material Button). This lets NodeModifier sizing
        // (w-full etc.) stretch the background properly.
        buttonContent(label: label, fontSize: fontSize, labelColor: effectiveLabelColor,
                      color: color, disabled: disabled, hasStyleBg: styleBg != 0,
                      pressCbId: pressCbId, longPressCbId: longPressCbId)
    }

    @ViewBuilder
    private func buttonContent(label: String, fontSize: Float, labelColor: Int,
                               color: Int, disabled: Bool, hasStyleBg: Bool,
                               pressCbId: Int, longPressCbId: Int) -> some View {
        let shape = RoundedRectangle(cornerRadius: 20)
        let fillWidth = (node.layout?.widthMode ?? 0) == SizeMode.fill

        // Build the styled label. Skip internal background if NodeModifier
        // already applies one via bg-* class.
        let styledLabel = Text(label)
            .font(.system(size: fontSize > 0 ? CGFloat(fontSize) : 16, weight: .medium))
            .foregroundColor(Color(argb: labelColor))
            .padding(.horizontal, 24)
            .frame(minHeight: 40)
            .frame(maxWidth: fillWidth ? .infinity : nil)

        let withBg = hasStyleBg
            ? AnyView(styledLabel)
            : AnyView(styledLabel.background(Color(argb: color)).clipShape(shape))

        let content = withBg
            .contentShape(shape)
            .opacity(disabled ? 0.5 : 1.0)

        if longPressCbId != 0 {
            content
                .onLongPressGesture(minimumDuration: 0.5) {
                    guard !disabled else { return }
                    NativeUIBridge.sendLongPressEvent(longPressCbId, nodeId: node.id)
                }
                .onTapGesture {
                    guard !disabled else { return }
                    if pressCbId != 0 {
                        NativeUIBridge.sendPressEvent(pressCbId, nodeId: node.id)
                    }
                }
        } else {
            content
                .onTapGesture {
                    guard !disabled else { return }
                    if pressCbId != 0 {
                        NativeUIBridge.sendPressEvent(pressCbId, nodeId: node.id)
                    }
                }
        }
    }
}

struct RenderTextInput: View {
    let node: NativeUINode
    @State private var text: String = ""

    var body: some View {
        let p = node.props
        let placeholder = p.getString("placeholder")
        let onChangeCb = p.getCallbackId("on_change")
        let onSubmitCb = p.getCallbackId("on_submit")
        let secure = p.getBool("secure")

        Group {
            if secure {
                SecureField(placeholder, text: $text)
            } else {
                TextField(placeholder, text: $text)
            }
        }
        .textFieldStyle(.roundedBorder)
        .onChange(of: text) { _, newValue in
            if onChangeCb != 0 {
                NativeUIBridge.sendTextChangeEvent(onChangeCb, nodeId: node.id, text: newValue)
            }
        }
        .onSubmit {
            if onSubmitCb != 0 {
                NativeUIBridge.sendSubmitEvent(onSubmitCb, nodeId: node.id, text: text)
            }
        }
        .onAppear {
            text = node.props.getString("value")
        }
    }
}

struct RenderToggle: View {
    let node: NativeUINode
    @State private var isOn: Bool = false

    var body: some View {
        let p = node.props
        let onChangeCb = p.getCallbackId("on_change")
        let disabled = p.getBool("disabled")
        let label = p.getString("label")

        Toggle(label, isOn: $isOn)
            .disabled(disabled)
            .onChange(of: isOn) { _, newValue in
                if onChangeCb != 0 {
                    NativeUIBridge.sendToggleChangeEvent(onChangeCb, nodeId: node.id, value: newValue)
                }
            }
            .onAppear {
                isOn = node.props.getBool("value")
            }
    }
}

struct RenderCheckbox: View {
    let node: NativeUINode
    @State private var checked: Bool = false

    var body: some View {
        let p = node.props
        let label = p.getString("label")
        let labelColor = p.getColor("label_color", default: 0xFF000000)
        let onChangeCb = p.getCallbackId("on_change")
        let disabled = p.getBool("disabled")

        Button(action: {
            guard !disabled else { return }
            checked.toggle()
            if onChangeCb != 0 {
                NativeUIBridge.sendCheckboxChangeEvent(onChangeCb, nodeId: node.id, value: checked)
            }
        }) {
            HStack(spacing: 8) {
                Image(systemName: checked ? "checkmark.square.fill" : "square")
                    .foregroundColor(checked ? .accentColor : .secondary)
                if !label.isEmpty {
                    Text(label)
                        .foregroundColor(Color(argb: labelColor))
                }
            }
        }
        .buttonStyle(.plain)
        .onAppear {
            checked = node.props.getBool("value")
        }
    }
}

struct RenderSlider: View {
    let node: NativeUINode
    @State private var value: Double = 0

    var body: some View {
        let p = node.props
        let min = Double(p.getFloat("min", default: 0))
        let max = Double(p.getFloat("max", default: 1))
        let step = Double(p.getFloat("step"))
        let onChangeCb = p.getCallbackId("on_change")
        let disabled = p.getBool("disabled")
        let color = p.getColor("color", default: 0)

        Group {
            if step > 0 {
                Slider(value: $value, in: min...max, step: step) {
                    EmptyView()
                } onEditingChanged: { editing in
                    if !editing && onChangeCb != 0 {
                        NativeUIBridge.sendSliderChangeEvent(onChangeCb, nodeId: node.id, value: Float(value))
                    }
                }
            } else {
                Slider(value: $value, in: min...max) {
                    EmptyView()
                } onEditingChanged: { editing in
                    if !editing && onChangeCb != 0 {
                        NativeUIBridge.sendSliderChangeEvent(onChangeCb, nodeId: node.id, value: Float(value))
                    }
                }
            }
        }
        .disabled(disabled)
        .tint(color != 0 ? Color(argb: color) : nil)
        .onAppear {
            value = Double(node.props.getFloat("value"))
        }
    }
}

struct RenderProgressBar: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let value = Double(p.getFloat("value")).clamped(to: 0...1)
        let color = p.getColor("color", default: 0xFF007AFF)

        ProgressView(value: value)
            .tint(Color(argb: color))
    }
}

struct RenderActivityIndicator: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let color = p.getColor("color", default: 0xFF007AFF)

        ProgressView()
            .tint(Color(argb: color))
    }
}

struct RenderRadioGroup: View {
    let node: NativeUINode
    @State private var selectedValue: String = ""

    var body: some View {
        let onChangeCb = node.props.getCallbackId("on_change")

        VStack(alignment: .leading, spacing: 4) {
            ForEach(node.children.filter { $0.type == "radio" }) { child in
                RenderRadio(
                    node: child,
                    selectedValue: selectedValue,
                    onSelect: { value in
                        selectedValue = value
                        if onChangeCb != 0 {
                            NativeUIBridge.sendRadioChangeEvent(onChangeCb, nodeId: node.id, value: value)
                        }
                    }
                )
            }
        }
        .onAppear {
            selectedValue = node.props.getString("value")
        }
    }
}

struct RenderRadio: View {
    let node: NativeUINode
    let selectedValue: String
    let onSelect: (String) -> Void

    var body: some View {
        let p = node.props
        let value = p.getString("value")
        let label = p.getString("label")
        let labelColor = p.getColor("label_color", default: 0xFF000000)
        let disabled = p.getBool("disabled")
        let isSelected = selectedValue == value

        Button(action: {
            guard !disabled else { return }
            onSelect(value)
        }) {
            HStack(spacing: 8) {
                Image(systemName: isSelected ? "circle.inset.filled" : "circle")
                    .foregroundColor(isSelected ? .accentColor : .secondary)
                if !label.isEmpty {
                    Text(label)
                        .foregroundColor(Color(argb: labelColor))
                }
            }
        }
        .buttonStyle(.plain)
    }
}

struct RenderIcon: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let name = p.getString("name")
        let size = CGFloat(p.getFloat("size", default: 24))
        let color = p.getColor("color", default: 0xFF000000)

        Image(systemName: getIconForName(name))
            .resizable()
            .aspectRatio(contentMode: .fit)
            .frame(width: size, height: size)
            .foregroundColor(Color(argb: color))
    }
}

struct RenderSelect: View {
    let node: NativeUINode
    @State private var selected: String = ""

    var body: some View {
        let p = node.props
        let options = p.getStringList("options")
        let onChangeCb = p.getCallbackId("on_change")
        let disabled = p.getBool("disabled")
        let placeholder = p.getString("placeholder")

        Menu {
            ForEach(options, id: \.self) { option in
                Button(option) {
                    selected = option
                    if onChangeCb != 0 {
                        NativeUIBridge.sendSelectChangeEvent(onChangeCb, nodeId: node.id, value: option)
                    }
                }
            }
        } label: {
            HStack {
                Text(selected.isEmpty ? placeholder : selected)
                    .foregroundColor(selected.isEmpty ? .secondary : .primary)
                Spacer()
                Image(systemName: "chevron.up.chevron.down")
                    .foregroundColor(.secondary)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(Color(.systemGray6))
            .cornerRadius(8)
        }
        .disabled(disabled)
        .onAppear {
            selected = node.props.getString("value")
        }
    }
}

struct RenderBadge: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let count = p.getInt("count")
        let color = p.getColor("color", default: 0xFFFF0000)
        let textColor = p.getColor("text_color", default: 0xFFFFFFFF)

        Text(count > 99 ? "99+" : "\(count)")
            .font(.system(size: 12, weight: .bold))
            .foregroundColor(Color(argb: textColor))
            .padding(.horizontal, 6)
            .padding(.vertical, 2)
            .background(Color(argb: color))
            .clipShape(Capsule())
    }
}

struct RenderCard: View {
    let node: NativeUINode

    var body: some View {
        VStack(spacing: 0) {
            ForEach(node.children) { child in
                RenderNode(node: child)
            }
        }
        .background(Color(.systemBackground))
        .cornerRadius(12)
        .shadow(radius: 2)
    }
}

struct RenderListItem: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let headline = p.getString("headline")
        let supporting = p.getString("supporting")
        let overline = p.getString("overline")
        let leadingIcon = p.getString("leading_icon")
        let trailingIcon = p.getString("trailing_icon")

        HStack(spacing: 16) {
            if !leadingIcon.isEmpty {
                Image(systemName: getIconForName(leadingIcon))
                    .frame(width: 24, height: 24)
                    .foregroundColor(.secondary)
            }

            VStack(alignment: .leading, spacing: 2) {
                if !overline.isEmpty {
                    Text(overline)
                        .font(.caption)
                        .foregroundColor(.secondary)
                }
                Text(headline)
                    .font(.body)
                if !supporting.isEmpty {
                    Text(supporting)
                        .font(.subheadline)
                        .foregroundColor(.secondary)
                }
            }

            Spacer()

            if !trailingIcon.isEmpty {
                Image(systemName: getIconForName(trailingIcon))
                    .frame(width: 24, height: 24)
                    .foregroundColor(.secondary)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
    }
}

struct RenderTabRow: View {
    let node: NativeUINode
    @State private var selectedIndex: Int = 0

    var body: some View {
        let onChangeCb = node.props.getCallbackId("on_change")
        let tabs = node.children.filter { $0.type == "tab" }

        if !tabs.isEmpty {
            VStack(spacing: 0) {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 0) {
                        ForEach(Array(tabs.enumerated()), id: \.element.id) { index, tab in
                            let label = tab.props.getString("label")
                            let icon = tab.props.getString("icon")
                            let isSelected = index == selectedIndex

                            Button(action: {
                                selectedIndex = index
                                if onChangeCb != 0 {
                                    NativeUIBridge.sendTabChangeEvent(onChangeCb, nodeId: node.id, index: index)
                                }
                            }) {
                                VStack(spacing: 4) {
                                    if !icon.isEmpty {
                                        Image(systemName: getIconForName(icon))
                                    }
                                    if !label.isEmpty {
                                        Text(label)
                                            .font(.subheadline)
                                    }
                                }
                                .padding(.horizontal, 16)
                                .padding(.vertical, 10)
                                .foregroundColor(isSelected ? .accentColor : .secondary)
                            }
                            .overlay(alignment: .bottom) {
                                if isSelected {
                                    Rectangle()
                                        .fill(Color.accentColor)
                                        .frame(height: 2)
                                }
                            }
                        }
                    }
                }
                Divider()
            }
        }
    }
}

struct RenderBottomSheet: View {
    let node: NativeUINode
    @State private var isPresented: Bool = false

    var body: some View {
        let visible = node.props.getBool("visible")
        let onDismissCb = node.props.getCallbackId("on_dismiss")

        Color.clear.frame(width: 0, height: 0)
            .sheet(isPresented: $isPresented, onDismiss: {
                if onDismissCb != 0 {
                    NativeUIBridge.sendSheetDismissEvent(onDismissCb, nodeId: node.id)
                }
            }) {
                VStack(spacing: 0) {
                    ForEach(node.children) { child in
                        RenderNode(node: child)
                    }
                }
                .presentationDetents([.medium, .large])
            }
            .onAppear { isPresented = visible }
            .onChange(of: visible) { _, v in isPresented = v }
    }
}

struct RenderChip: View {
    let node: NativeUINode
    @State private var isSelected: Bool = false

    var body: some View {
        let p = node.props
        let label = p.getString("label")
        let onChangeCb = p.getCallbackId("on_change")
        let iconName = p.getString("icon")

        Button(action: {
            isSelected.toggle()
            if onChangeCb != 0 {
                NativeUIBridge.sendToggleChangeEvent(onChangeCb, nodeId: node.id, value: isSelected)
            }
        }) {
            HStack(spacing: 6) {
                if !iconName.isEmpty {
                    Image(systemName: getIconForName(iconName))
                        .font(.system(size: 14))
                }
                Text(label)
                    .font(.subheadline)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 6)
            .background(isSelected ? Color.accentColor.opacity(0.15) : Color(.systemGray6))
            .foregroundColor(isSelected ? .accentColor : .primary)
            .clipShape(Capsule())
            .overlay(Capsule().stroke(isSelected ? Color.accentColor : Color(.systemGray4), lineWidth: 1))
        }
        .buttonStyle(.plain)
        .onAppear {
            isSelected = node.props.getBool("selected")
        }
    }
}

// MARK: - Alignment Helpers

func resolveHorizontalAlignment(_ alignItems: Int) -> HorizontalAlignment {
    switch alignItems {
    case 0: return .leading
    case 1: return .center
    case 2: return .trailing
    default: return .leading
    }
}

func resolveVerticalAlignment(_ alignItems: Int) -> VerticalAlignment {
    switch alignItems {
    case 0: return .top
    case 1: return .center
    case 2: return .bottom
    default: return .top
    }
}

// MARK: - Color / Utility Helpers

func hasEdges(_ top: Float, _ right: Float, _ bottom: Float, _ left: Float) -> Bool {
    top > 0 || right > 0 || bottom > 0 || left > 0
}

extension Double {
    func clamped(to range: ClosedRange<Double>) -> Double {
        Swift.min(Swift.max(self, range.lowerBound), range.upperBound)
    }
}

// MARK: - Click Handler Extension

extension View {
    @ViewBuilder
    func applyClickHandlers(node: NativeUINode) -> some View {
        if node.onPress != 0 && node.onLongPress != 0 {
            // Both tap and long press: use onLongPressGesture for long press,
            // onTapGesture for tap. Order matters — onLongPressGesture must
            // come first so it gets priority over the tap recognizer.
            self
                .onLongPressGesture(minimumDuration: 0.5) {
                    NativeUIBridge.sendLongPressEvent(node.onLongPress, nodeId: node.id)
                }
                .onTapGesture {
                    NativeUIBridge.sendPressEvent(node.onPress, nodeId: node.id)
                }
        } else if node.onLongPress != 0 {
            self
                .onLongPressGesture(minimumDuration: 0.5) {
                    NativeUIBridge.sendLongPressEvent(node.onLongPress, nodeId: node.id)
                }
        } else if node.onPress != 0 {
            self
                .onTapGesture {
                    NativeUIBridge.sendPressEvent(node.onPress, nodeId: node.id)
                }
        } else {
            self
        }
    }

    @ViewBuilder
    func maybeFlexGrow(_ node: NativeUINode, axis: Axis = .vertical) -> some View {
        let grow = node.layout?.flexGrow ?? 0
        if grow > 0 {
            // Use layoutPriority to approximate weighted flex-grow.
            // Higher grow values get more space.
            switch axis {
            case .vertical:
                self.frame(maxHeight: .infinity)
                    .layoutPriority(Double(grow))
            case .horizontal:
                self.frame(maxWidth: .infinity)
                    .layoutPriority(Double(grow))
            }
        } else {
            self
        }
    }
}
