import UIKit

class ToastManager {
    static let shared = ToastManager()

    private var toastQueue: [(message: String, duration: TimeInterval)] = []
    private var isShowingToast = false

    private init() {}

    func show(message: String, duration: TimeInterval? = nil) {
        let calculatedDuration = duration ?? calculateDuration(for: message)

        // UIKit operations must run on the main thread.
        // Bridge calls from the queue worker arrive on a background pthread.
        if Thread.isMainThread {
            toastQueue.append((message, calculatedDuration))
            showNextToastIfPossible()
        } else {
            DispatchQueue.main.async { [self] in
                toastQueue.append((message, calculatedDuration))
                showNextToastIfPossible()
            }
        }
    }

    private func calculateDuration(for message: String) -> TimeInterval {
        // Calculate duration based on reading speed
        // Average reading speed is ~200-250 words per minute (~4 words per second)
        // We'll use ~3 words per second to be safe, plus a base duration
        let wordCount = message.split(separator: " ").count
        let baseDuration: TimeInterval = 2.0
        let readingTime = Double(wordCount) / 3.0

        // Minimum 2 seconds, maximum 10 seconds
        return min(max(baseDuration + readingTime, 2.0), 10.0)
    }

    private func showNextToastIfPossible() {
        guard !isShowingToast, !toastQueue.isEmpty else { return }

        isShowingToast = true
        let (message, duration) = toastQueue.removeFirst()
        displayToast(message: message, duration: duration)
    }

    private func displayToast(message: String, duration: TimeInterval) {
        guard let window = UIApplication.shared.connectedScenes
            .compactMap({ $0 as? UIWindowScene })
            .first?.windows
            .first(where: { $0.isKeyWindow }) else {
                isShowingToast = false
                showNextToastIfPossible()
                return
            }

        let toastLabel = UILabel()
        toastLabel.backgroundColor = UIColor.black.withAlphaComponent(0.6)
        toastLabel.textColor = .white
        toastLabel.textAlignment = .center
        toastLabel.text = message
        toastLabel.numberOfLines = 0 // Enable multi-line
        toastLabel.alpha = 0.0
        toastLabel.layer.cornerRadius = 10
        toastLabel.clipsToBounds = true

        // Calculate safe area insets
        let safeAreaInsets = window.safeAreaInsets
        let horizontalMargin: CGFloat = 10.0

        // Calculate maximum width (screen width - safe area - margins)
        let maxWidth = window.frame.width - safeAreaInsets.left - safeAreaInsets.right - (horizontalMargin * 2)

        // Internal padding
        let horizontalPadding: CGFloat = 20.0
        let verticalPadding: CGFloat = 16.0

        // Calculate text size with wrapping
        let maxTextWidth = maxWidth - (horizontalPadding * 2)
        let maxSize = CGSize(width: maxTextWidth, height: CGFloat.greatestFiniteMagnitude)
        let textSize = toastLabel.sizeThatFits(maxSize)

        // Calculate toast dimensions with padding
        let toastWidth = min(textSize.width + (horizontalPadding * 2), maxWidth)
        let toastHeight = textSize.height + (verticalPadding * 2)

        // Position toast at bottom, respecting safe area
        let xPosition = (window.frame.width - toastWidth) / 2
        let yPosition = window.frame.height - safeAreaInsets.bottom - toastHeight - 100

        toastLabel.frame = CGRect(
            x: xPosition,
            y: yPosition,
            width: toastWidth,
            height: toastHeight
        )

        window.addSubview(toastLabel)

        UIView.animate(withDuration: 0.5, animations: {
            toastLabel.alpha = 1.0
        }) { _ in
            UIView.animate(withDuration: 0.5, delay: duration, options: .curveEaseOut, animations: {
                toastLabel.alpha = 0.0
            }) { _ in
                toastLabel.removeFromSuperview()
                self.isShowingToast = false
                self.showNextToastIfPossible()
            }
        }
    }
}

func showToast(message: String, duration: TimeInterval? = nil) {
    ToastManager.shared.show(message: message, duration: duration)
}
