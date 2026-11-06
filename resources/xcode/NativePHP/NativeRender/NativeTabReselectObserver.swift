import SwiftUI
import UIKit

/// Observes user selections while preserving SwiftUI's tab-controller delegate.
struct NativeTabReselectObserver: UIViewControllerRepresentable {
    let onReselect: () -> Void

    func makeUIViewController(context: Context) -> NativeTabReselectController {
        NativeTabReselectController(onReselect: onReselect)
    }

    func updateUIViewController(_ controller: NativeTabReselectController, context: Context) {
        controller.proxy.onReselect = onReselect
        controller.attach()
    }

    static func dismantleUIViewController(_ controller: NativeTabReselectController, coordinator: ()) {
        controller.detach()
    }
}

final class NativeTabReselectController: UIViewController {
    let proxy: NativeTabReselectDelegate
    private weak var observedController: UITabBarController?

    init(onReselect: @escaping () -> Void) {
        proxy = NativeTabReselectDelegate(onReselect: onReselect)
        super.init(nibName: nil, bundle: nil)
    }

    required init?(coder: NSCoder) { nil }

    override func loadView() {
        view = UIView(frame: .zero)
        view.isUserInteractionEnabled = false
    }

    override func didMove(toParent parent: UIViewController?) {
        super.didMove(toParent: parent)
        attach()
    }

    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        attach()
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        attach()
    }

    func attach() {
        guard let controller = tabBarController else { return }
        if observedController !== controller {
            detach()
            observedController = controller
        }
        guard controller.delegate !== proxy else { return }

        // A newly selected tab can mount before the old observer is dismantled.
        // Keep the real delegate, rather than chaining observers together.
        if let previous = controller.delegate as? NativeTabReselectDelegate {
            proxy.original = previous.original
        } else {
            proxy.original = controller.delegate
        }
        controller.delegate = proxy
    }

    func detach() {
        if let controller = observedController, controller.delegate === proxy {
            controller.delegate = proxy.original
        }
        observedController = nil
        proxy.cancel()
    }
}

final class NativeTabReselectDelegate: NSObject, UITabBarControllerDelegate {
    weak var original: UITabBarControllerDelegate?
    var onReselect: () -> Void
    private var queued = false
    private var generation = 0

    init(onReselect: @escaping () -> Void) {
        self.onReselect = onReselect
    }

    override func responds(to selector: Selector!) -> Bool {
        super.responds(to: selector) || original?.responds(to: selector) == true
    }

    override func forwardingTarget(for selector: Selector!) -> Any? {
        original?.responds(to: selector) == true ? original : super.forwardingTarget(for: selector)
    }

    func tabBarController(_ controller: UITabBarController, shouldSelect viewController: UIViewController) -> Bool {
        let allowed = original?.tabBarController?(controller, shouldSelect: viewController) ?? true
        if allowed, controller.selectedViewController === viewController {
            enqueue()
        } else if allowed {
            cancel()
        }
        return allowed
    }

    @available(iOS 18.0, *)
    func tabBarController(_ controller: UITabBarController, shouldSelectTab tab: UITab) -> Bool {
        let allowed = original?.tabBarController?(controller, shouldSelectTab: tab) ?? true
        if allowed, controller.selectedTab === tab {
            enqueue()
        } else if allowed {
            cancel()
        }
        return allowed
    }

    func cancel() {
        generation += 1
        queued = false
    }

    private func enqueue() {
        guard !queued else { return }
        queued = true
        let requestedGeneration = generation
        DispatchQueue.main.async { [weak self] in
            guard let self, self.generation == requestedGeneration else { return }
            self.queued = false
            self.onReselect()
        }
    }
}
