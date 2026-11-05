import CryptoKit
import Foundation

/// Signs the device events the native shell POSTs to `/_native/api/events`, so
/// PHP can tell a genuine native event from one a web view page forged.
///
/// A page can POST to that endpoint itself (the `api` routes carry no
/// middleware), so without a signature a script — an XSS, or a third-party
/// script the app bundles — could fake a `PaymentCompleted` or a
/// `Biometric.Completed(success: true)` and fire the app's listeners and
/// pending callbacks as if the device sent it. The shell signs each event with
/// the app key, which lives in the Keychain and is never handed to the page,
/// over a per-event nonce, a timestamp and the exact body bytes. PHP verifies
/// with the same key and refuses anything stale, replayed or unsigned once
/// enforcement is on (`NATIVEPHP_EVENTS_SIGNED`).
///
/// The purpose string, message layout and header names match
/// `Native\Mobile\Support\NativeEventSignature` on the PHP side byte for byte.
/// Change the two together. Android twin: `security/NativeEventSignature.kt`.
enum NativeEventSignature {
    static let nonceHeader = "X-Native-Event-Nonce"
    static let timestampHeader = "X-Native-Event-Timestamp"
    static let signatureHeader = "X-Native-Event-Signature"

    /// What the signature is for, mixed in so one made for something else can't pass.
    private static let purpose = "nativephp.native-event"

    struct Signed {
        let nonce: String
        let timestamp: String
        let signature: String
    }

    /// Sign `body` — the exact bytes that will be POSTed. Nil when there is no
    /// usable app key; the caller then posts unsigned, and PHP, if it is
    /// enforcing, drops the event rather than trusting it.
    static func sign(body: String) -> Signed? {
        guard let key = rawKey() else {
            return nil
        }

        let nonce = randomHex(16)
        let timestamp = String(Int(Date().timeIntervalSince1970))
        let message = purpose + "\n" + nonce + "\n" + timestamp + "\n" + body

        let code = HMAC<SHA256>.authenticationCode(
            for: Data(message.utf8),
            using: SymmetricKey(data: key)
        )

        return Signed(nonce: nonce, timestamp: timestamp, signature: hex(code))
    }

    /// The app key as raw bytes, matching PHP's `app('encrypter')->getKey()`.
    /// The env value is `base64:<base64>`; the raw key is the decoded tail.
    private static func rawKey() -> Data? {
        guard let raw = getenv("APP_KEY") else {
            return nil
        }

        let value = String(cString: raw)
        let prefix = "base64:"

        guard value.hasPrefix(prefix) else {
            return nil
        }

        return Data(base64Encoded: String(value.dropFirst(prefix.count)))
    }

    private static func randomHex(_ byteCount: Int) -> String {
        var bytes = [UInt8](repeating: 0, count: byteCount)
        _ = SecRandomCopyBytes(kSecRandomDefault, byteCount, &bytes)
        return bytes.map { String(format: "%02x", $0) }.joined()
    }

    private static func hex<S: Sequence>(_ bytes: S) -> String where S.Element == UInt8 {
        bytes.map { String(format: "%02x", $0) }.joined()
    }
}
