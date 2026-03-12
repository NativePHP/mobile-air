import UIKit

// Canvas is now just a container with clipsToBounds = true.
// Yoga handles all child positioning via computed {x, y, width, height}.
// No special renderer needed — the main NativeUIViewRenderer handles it.
// Canvas nodes clip their children to bounds (unlike stack which doesn't).

// Note: Canvas-specific clipping is handled in NativeUIViewRenderer.applyStyle
// via node.type == "canvas" check on clipsToBounds.
