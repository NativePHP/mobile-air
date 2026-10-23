---
name: nativephp-agent-loop
description: "Autonomous build-verify loop for NativePHP Mobile. Activate when the user asks you to build, run, launch, verify, screenshot, or iterate on a NativePHP mobile app on a simulator or emulator — and drive the loop yourself instead of telling the user to run commands. Uses the headless --json artisan commands (native:run, native:status, native:screenshot, native:tail, native:open-url, native:devices) to build, launch, observe and re-verify."
---

# NativePHP Mobile — Agent Build/Verify Loop

Drive the device cycle yourself: edit code → build → launch on a simulator/emulator → look at the
screen → fix → re-verify. Everything here uses the headless `--json` command surface in core.

## When this applies

Activate when the user asks you to **build, run, verify, or iterate** on the app (not merely to write code)
and the target is a **simulator/emulator with a debug build**. Otherwise follow `nativephp-mobile`'s
"Build Commands — Run Them Yourself" and "Building and Running" sections.

**Always ask the user first** before: physical devices, release/bundle builds, `native:install`, uninstalling
apps, or `simctl shutdown`/`erase`.

## Starting from zero (no app yet)

```bash
composer create-project nativephp/mobile-starter <dir> --no-interaction
cd <dir>
# The starter pre-fills NATIVEPHP_APP_ID (unique) and version envs in .env.
# Add NATIVEPHP_DEEPLINK_SCHEME=<scheme> so native:open-url can drive navigation.
php artisan native:install --force --no-interaction   # runtime + nativephp/{ios,android}
```

Then the first `native:run <os> <udid> --build=debug --no-tty --json` is the baseline build.

## Preconditions & claiming a device

1. `php artisan native:devices --json` — list simulators/emulators. Prefer one that is already `booted`.
2. Record your target so every later command is unambiguous (and other agents don't collide):
   write `nativephp/agent-device.json` = `{"platform":"ios","udid":"<UDID>"}`. Every `--json` command
   resolves this automatically; you can also pass `--device=<udid>` explicitly.
3. If nothing is booted: iOS `xcrun simctl boot <udid>` (pick the newest iPhone); Android
   `php artisan native:emulator android`, then re-run `native:devices`.
4. For `native:open-url`, set `NATIVEPHP_DEEPLINK_SCHEME=<scheme>` in `.env` (a rebuild bakes it in).

## The loop

```
edit code
└─ php artisan native:run <ios|android> <udid> --build=debug --no-tty --json   (allow 10 min for first build)
   ├─ ok:false, stage:build|install   → read .logTail (or native:tail); fix; rebuild
   ├─ ok:false, stage:verify          → app built but isn't running: boot fatal.
   │                                     laravel.log is usually EMPTY here (Laravel never got up).
   │                                     native:tail <os> --fatals --json; fix; rebuild
   └─ ok:true
      └─ php artisan native:screenshot <os> --json → Read the PNG → judge against the goal
         └─ exercise: php artisan native:open-url "<scheme>://<route>" <os> --json → screenshot each screen
            └─ all screens verified, nothing in the log → done. Report with the screenshot paths.
```

`native:run --json` emits one JSON object on the last stdout line. On success:
`{"ok":true,"platform":...,"device":...,"appId":...,"buildType":"debug","pid":...,"buildLog":...,"durationMs":...}`.
On failure it carries `stage` (`validate|devices|build|install|launch|verify`), `error`, and — for build/install
— a `logTail`. Exit code is 0 only when `ok:true`.

### "Did it boot?" — the definition

Treat the app as booted-OK when: `native:run --json` returned `ok:true` **and** `native:status <os> --json`
shows `running:true` a few seconds later **and** the screenshot is not the red "Something went wrong"
error screen.

## Reading exceptions

Exceptions that reach Laravel go through its normal reporting, so `laravel.log` on the device is the
first stop:

```bash
php artisan native:tail <os> --lines=100 --json    # the app's laravel.log, off the device
```

That covers anything thrown while the app is running — including native (EDGE) screens, which report
through Laravel like the rest of the app.

**It does not cover boot fatals.** An app that dies before Laravel is up writes nothing to `laravel.log`.
The runtime records those on the device instead, along with true PHP fatals and uncaught throwables:

```bash
php artisan native:tail <os> --fatals --json       # {"ok":true,"events":[{"kind":"boot_fatal","exception":{...}}]}
```

Each event's `exception.app_frame` is the first line in the app's own code, which is usually the one to fix.
An empty `events` list means nothing has crashed. Do not read a clean `laravel.log` as evidence the app
booted: when `native:run --json` returns `ok:false, stage:verify`, check `--fatals` and the screenshot.

Anything richer (timelines, component state, screen trees, interactive inspection) comes from a devtools
package rather than core; follow that package's own skill if the project has one.

## Exercising the app

- `php artisan native:open-url "<scheme>://<route>" <os> --json` for navigation. Note iOS simulators show a
  one-time "Open in <App>?" confirmation for custom schemes.
- Raw `adb` (`input tap/text/keyevent`, `uiautomator dump`) for Android.
- `xcrun simctl` for iOS simulator lifecycle.

Screenshot after every interaction and judge against the goal.

## Hygiene

- Never use `--watch` for discrete build-loop iterations (it blocks).
- Never edit generated files under `nativephp/ios` or `nativephp/android`.
- One rebuild at a time per app repo; don't run two agents against the same repo.
- Remove any throwaway/debug code you added before reporting done.
- Never `simctl shutdown`/`erase` a simulator unless the user asked — other agents may share it.
