import ImageIO
import SwiftUI
import UIKit

/// A view that replicates the launch screen appearance.
/// This allows the app to appear launched while heavy initialization continues in the background.
struct SplashView: View {
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        GeometryReader { geometry in
            ZStack {
                // Background color matching launch screen (white)
                Color.white
                    .ignoresSafeArea()

                // LaunchImage scaled to fill (matching LaunchScreen.storyboard scaleAspectFill)
                Image("LaunchImage")
                    .resizable()
                    .aspectRatio(contentMode: .fill)
                    .frame(width: geometry.size.width, height: geometry.size.height)
                    .clipped()
                    .ignoresSafeArea()

                // An animated splash.webp plays over the launch image, which shows until its first frame is decoded.
                if let animation = splashAnimation {
                    AnimatedSplash(data: animation)
                        .frame(width: geometry.size.width, height: geometry.size.height)
                        .ignoresSafeArea()
                }
            }
        }
        .ignoresSafeArea()
    }

    private var splashAnimation: Data? {
        let dark = colorScheme == .dark ? NSDataAsset(name: "SplashAnimationDark") : nil
        return (dark ?? NSDataAsset(name: "SplashAnimation"))?.data
    }
}

private struct AnimatedSplash: UIViewRepresentable {
    let data: Data

    func makeUIView(context: Context) -> AnimatedSplashView {
        AnimatedSplashView(data: data)
    }

    func updateUIView(_ uiView: AnimatedSplashView, context: Context) {}
}

/// Plays an animated WebP with the frame delays and loop count stored in it, and stops on its last frame.
/// Each frame is decoded off the main thread while the one before it is on screen.
private final class AnimatedSplashView: UIView {
    private let source: CGImageSource?
    private let frameCount: Int
    private let loopCount: Int
    private let decodeQueue = DispatchQueue(label: "com.nativephp.splash-animation")
    private var started = false
    private var completedLoops = 0

    init(data: Data) {
        let imageSource = CGImageSourceCreateWithData(data as CFData, nil)
        source = imageSource
        frameCount = imageSource.map { CGImageSourceGetCount($0) } ?? 0
        if let imageSource, let webp = AnimatedSplashView.webp(CGImageSourceCopyProperties(imageSource, nil)) {
            loopCount = webp[kCGImagePropertyWebPLoopCount as String] as? Int ?? 0
        } else {
            loopCount = 0
        }

        super.init(frame: .zero)

        clipsToBounds = true
        layer.contentsGravity = .resizeAspectFill
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) has not been implemented")
    }

    override func didMoveToWindow() {
        super.didMoveToWindow()

        guard window != nil, !started, frameCount > 1 else { return }
        started = true
        show(frame: 0, at: .now())
    }

    private func show(frame index: Int, at deadline: DispatchTime) {
        guard let source else { return }

        decodeQueue.async { [weak self] in
            let options = [kCGImageSourceShouldCacheImmediately: true] as CFDictionary
            let image = CGImageSourceCreateImageAtIndex(source, index, options)

            DispatchQueue.main.asyncAfter(deadline: deadline) {
                guard let self, self.window != nil, let image else { return }

                if self.layer.contents == nil {
                    self.backgroundColor = .white
                }
                self.layer.contents = image
                self.showNext(after: index)
            }
        }
    }

    private func showNext(after index: Int) {
        let deadline = DispatchTime.now() + delay(of: index)

        if index + 1 < frameCount {
            show(frame: index + 1, at: deadline)
            return
        }

        completedLoops += 1
        if loopCount == 0 || completedLoops < loopCount {
            show(frame: 0, at: deadline)
        }
    }

    private func delay(of index: Int) -> TimeInterval {
        guard let source, let webp = Self.webp(CGImageSourceCopyPropertiesAtIndex(source, index, nil)) else {
            return 0.1
        }

        let delay = webp[kCGImagePropertyWebPUnclampedDelayTime as String] as? TimeInterval
            ?? webp[kCGImagePropertyWebPDelayTime as String] as? TimeInterval
            ?? 0.1

        return delay > 0 ? delay : 0.1
    }

    private static func webp(_ properties: CFDictionary?) -> [String: Any]? {
        (properties as? [String: Any])?[kCGImagePropertyWebPDictionary as String] as? [String: Any]
    }
}

#Preview {
    SplashView()
}
