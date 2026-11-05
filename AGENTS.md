# AGENTS.md

Guidance for AI agents (and humans) working on the **NativePHP Mobile** package itself.

This package runs a full PHP runtime inside a native iOS/Android app. A screen can embed a
web view, and web-view script can reach PHP through bridge calls and the `/_native/api/*`
endpoints — so treat anything a web view can reach as untrusted input.

- Building apps *with* NativePHP: activate the `nativephp-mobile` skill.
- Design notes for the internals live in `docs/` (`async-task-design.md`,
  `native-callback-api-design.md`, …).
- Contribution process and security reporting: `CONTRIBUTING.md` and `SECURITY.md`.

## Deserialization safety — never unserialize bytes the app didn't authenticate

`unserialize()` instantiates whatever classes its bytes name and runs their magic methods
(`__wakeup`, `__destruct`, …). Web-view script can reach any PHP path that reads serialized
data — a cache entry, a spool or temp file, a native-event payload, a queue message — so
**no value that crosses one of those boundaries may reach `unserialize()` until the app has
proven it wrote that value, and that it is the exact shape the app expects.**

When you add or change any durable or cross-lane transport that serializes PHP:

- **Authenticate with the app key on write, verify on read.** Use the key Laravel's
  encrypter holds (`app('encrypter')->getKey()`). HMAC-sign the serialized bytes when they
  only need integrity (`GlobalEventTransport::sign()`, `NativeCallbacks`); encrypt-seal them
  with `Illuminate\Encryption\Encrypter` when they must also stay secret
  (`AsyncTaskTransport::seal()`). Verify the signature with `hash_equals`, or decrypt,
  **before** you call `unserialize()` — never after, and never "just to see what's in it".
- **Bind a purpose string** into the signature/seal so a value the app key signed for one
  feature can't be replayed as another: `nativephp.global-event:`, `nativephp.native-callback:`,
  `nativephp.async-task:`. Bind any correlating id (task id, callback id) too, so a sealed
  value can't be moved to a different slot.
- **Discard anything that doesn't line up.** If the value isn't a well-formed signed/sealed
  envelope, the signature doesn't match, there is no app key, or it carries any shape other
  than the one you expect, drop it, log once, and return null — never fall back to
  unserializing it. A missing app key means the durable copy is skipped on write and dropped
  on read; an in-memory/in-process copy may still serve the current process.
- **Keep the only unsigned tier in-process.** The moment data is written somewhere it can be
  read back, it must be authenticated. Prefer `json_encode`/`json_decode` for data that does
  not need PHP objects at all.

The three existing lanes are the reference implementations — mirror whichever fits:

| Lane | File | How it's protected |
|---|---|---|
| Async task spool | `src/Support/AsyncTaskTransport.php` | encrypt-seal (secrecy + integrity) |
| Cross-lane events | `src/Support/GlobalEventTransport.php` | HMAC sign (integrity) |
| Native callbacks | `src/Support/NativeCallbacks.php` | HMAC sign (integrity) |

Add tests that prove a tampered, forged, unsigned, or wrong-key value is dropped **without
being unserialized** — a small class with a `__wakeup` that flips a static flag is the
standard probe: register it in a forged payload and assert the flag stays false.
