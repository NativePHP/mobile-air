import SwiftUI

struct RenderImage: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let src = p.getString("src")
        let fit = p.getInt("fit")
        let tintColor = p.getColor("tint_color", default: 0)

        // fit: 0=None, 1=Fit, 2=Crop, 3=FillBounds, 4=Inside
        let isCrop = fit == 2
        let stretchToFill = fit == 3

        if !src.isEmpty, let url = URL(string: src) {
            if isCrop {
                // Crop mode: GeometryReader gets the actual proposed size from
                // NodeModifier's frame, then constrains the filled image to
                // exactly that size. Without this, .aspectRatio(.fill) reports
                // an oversized layout that expands the parent container.
                GeometryReader { geo in
                    AsyncImage(url: url) { phase in
                        switch phase {
                        case .empty:
                            ProgressView()
                                .frame(width: geo.size.width, height: geo.size.height)
                        case .success(let image):
                            let view = image
                                .resizable()
                                .aspectRatio(contentMode: .fill)
                                .frame(width: geo.size.width, height: geo.size.height)
                                .clipped()

                            if tintColor != 0 {
                                view.foregroundColor(Color(argb: tintColor))
                            } else {
                                view
                            }
                        case .failure:
                            failurePlaceholder(src: src)
                        @unknown default:
                            EmptyView()
                        }
                    }
                }
            } else {
                // Non-crop modes: image stays within proposed bounds naturally
                AsyncImage(url: url) { phase in
                    switch phase {
                    case .empty:
                        ProgressView()
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    case .success(let image):
                        // fit=0 (None): natural size
                        // fit=1 (Fit): scale to fit, preserve aspect ratio
                        // fit=3 (FillBounds): stretch, ignore aspect ratio
                        // fit=4 (Inside): same as Fit
                        let view: AnyView = if fit == 0 {
                            AnyView(image)
                        } else if stretchToFill {
                            AnyView(image.resizable())
                        } else {
                            AnyView(image.resizable().aspectRatio(contentMode: .fit))
                        }

                        if tintColor != 0 {
                            view.foregroundColor(Color(argb: tintColor))
                        } else {
                            view
                        }
                    case .failure:
                        failurePlaceholder(src: src)
                    @unknown default:
                        EmptyView()
                    }
                }
            }
        }
    }

    private func failurePlaceholder(src: String) -> some View {
        Color(.systemGray5)
            .overlay {
                Text(String(src.suffix(20)))
                    .font(.system(size: 10))
                    .foregroundColor(.gray)
            }
    }
}
