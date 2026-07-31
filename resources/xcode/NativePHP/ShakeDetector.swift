import UIKit

/// App-wide device-shake detection.
///
/// UIKit delivers a shake as a motion event up the responder chain; if no
/// responder consumes it, it reaches the key `UIWindow`. Overriding
/// `motionEnded` here catches the shake regardless of which view is first
/// responder, and forwards it to PHP over the native-event channel as
/// `Native\Mobile\Events\Motion\ShakeDetected`.
///
/// On the PHP side, handle it in a NativeComponent:
///
///     #[On(ShakeDetected::class)]
///     public function onShake(): void { ... }
///
/// This rides the same `sendNativeEvent` path as camera/gallery events — no
/// node, no binary wire-format change.
extension UIWindow {
    open override func motionEnded(_ motion: UIEvent.EventSubtype, with event: UIEvent?) {
        super.motionEnded(motion, with: event)

        if motion == .motionShake {
            NativeElementBridge.sendNativeEvent(
                eventName: "Native\\Mobile\\Events\\Motion\\ShakeDetected",
                payloadJson: "{}"
            )
        }
    }
}
