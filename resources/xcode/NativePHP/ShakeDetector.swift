import UIKit

/// App-wide device-shake detection.
///
/// UIKit delivers a shake as a motion event up the responder chain; if no
/// responder consumes it, it reaches the key `UIWindow`. Overriding
/// `motionEnded` here catches the shake regardless of which view is first
/// responder, and forwards it to PHP as
/// `Native\Mobile\Events\Motion\ShakeDetected`.
///
/// On the PHP side, handle it in a NativeComponent:
///
///     #[On(ShakeDetected::class)]
///     public function onShake(): void { ... }
///
/// …or anywhere via `Event::listen(ShakeDetected::class, ...)`.
///
/// This rides `LaravelBridge.send` like every other device event, so it
/// reaches the page and PHP on webview screens (coordinator: JS CustomEvent
/// + POST) and the element queue on EDGE screens — a raw
/// `NativeElementBridge.sendNativeEvent` would only feed the queue, which
/// nothing drains while a webview screen is showing.
extension UIWindow {
    open override func motionEnded(_ motion: UIEvent.EventSubtype, with event: UIEvent?) {
        super.motionEnded(motion, with: event)

        if motion == .motionShake {
            LaravelBridge.shared.send?(
                "Native\\Mobile\\Events\\Motion\\ShakeDetected",
                [:]
            )
        }
    }
}
