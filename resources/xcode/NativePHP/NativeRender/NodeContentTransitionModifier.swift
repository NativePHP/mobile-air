import SwiftUI

/// Animates in-place text changes, driven by the `content_transition` prop:
///   - `numeric`     → `.contentTransition(.numericText(value:))` — the
///                     rolling-counter treatment. Digits roll up when the
///                     value increases and down when it decreases; the
///                     direction comes from parsing the text as a Double,
///                     falling back to the undirected `.numericText()` for
///                     strings that don't parse ("1,204 pts").
///   - `opacity`     → `.contentTransition(.opacity)` (crossfade).
///   - `interpolate` → `.contentTransition(.interpolate)` (glyph morph;
///                     weight/size changes on the same string).
///
/// Applied at the node level rather than inside the plugin's text renderer:
/// `.contentTransition` is an inherited environment value that descendant
/// `Text` picks up — same mechanism `NodeTextSelectionModifier` relies on for
/// `.textSelection`. The companion `.animation(_, value: text)` is what makes
/// the content change animatable; it's keyed on the text string alone so it
/// can't retrigger from unrelated prop churn.
///
/// The transition needs a stable node identity across publishes (the roll
/// animates a CHANGE on one view, not a swap between two) — keyed elements
/// (`->key(...)`) or stable positional ids both satisfy this.
struct NodeContentTransitionModifier: ViewModifier {
    let props: GenericProps

    func body(content: Content) -> some View {
        let kind = props.getString("content_transition", default: "")
        if kind.isEmpty {
            content
        } else {
            let text = props.getString("text", default: "")
            content
                .contentTransition(Self.transition(kind, text: text))
                .animation(animation, value: text)
        }
    }

    private static func transition(_ kind: String, text: String) -> ContentTransition {
        switch kind {
        case "numeric":
            if let value = Double(text) {
                return .numericText(value: value)
            }
            return .numericText()
        case "interpolate":
            return .interpolate
        default:
            return .opacity
        }
    }

    /// Honors the node's `animate-duration` / `animate-easing` props so PHP
    /// can tune the roll; defaults to the system animation when unset.
    private var animation: Animation {
        let durationMs = props.getFloat("animate-duration", default: 0)
        guard durationMs > 0 else { return .default }

        let duration = Double(durationMs) / 1000.0
        switch props.getString("animate-easing", default: "ease-in-out") {
        case "linear":      return .linear(duration: duration)
        case "ease-in":     return .easeIn(duration: duration)
        case "ease-out":    return .easeOut(duration: duration)
        case "spring":      return .spring(duration: duration)
        default:            return .easeInOut(duration: duration)
        }
    }
}
