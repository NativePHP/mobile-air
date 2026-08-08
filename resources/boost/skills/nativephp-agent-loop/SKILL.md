---
name: nativephp-agent-loop
description: "Autonomous build-verify loop for NativePHP Mobile. Activate when the user asks you to build, run, launch, verify, screenshot, or iterate on a NativePHP mobile app on a simulator or emulator — and drive the loop yourself instead of telling the user to run commands. Uses the headless --json artisan commands (native:run, native:status, native:screenshot, native:tail, native:open-url, native:devices) and the devtools exception event log to build, observe, catch runtime exceptions, fix, and re-verify."
---

# NativePHP Mobile — Agent Build/Verify Loop

This skill lets you drive the whole device cycle yourself: edit code → build → launch on a
simulator/emulator → look at the screen → catch runtime exceptions → fix → re-verify. It relies on the
headless `--json` command surface and the `nativephp/devtools` exception pipeline.

## When this applies

Activate when the user asks you to **build, run, verify, or iterate** on the app (not merely to write code)
and the target is a **simulator/emulator with a debug build**. If those conditions do not hold, fall back to
`nativephp-mobile`'s "Build Commands — Who Runs Them" rule (tell the user; don't run builds).

**Always ask the user first** before: physical devices, release/bundle builds, `native:install`, uninstalling
apps, or `simctl shutdown`/`erase`.

## Starting from zero (no app yet)

When the goal is a NEW app, scaffold before entering the loop:

```bash
composer create-project nativephp/mobile-starter <dir> --no-interaction
cd <dir>
# The starter pre-fills NATIVEPHP_APP_ID (unique) and version envs in .env.
# Add NATIVEPHP_DEEPLINK_SCHEME=<scheme> so native:open-url can drive navigation.
php artisan native:install --force --no-interaction   # runtime + nativephp/{ios,android}
```

Then the first `native:run <os> <udid> --build=debug --no-tty --json` is the baseline build and the loop below
applies unchanged. Install `nativephp/devtools` (composer require in the app + `native:plugin:register`, then
`php artisan vendor:publish --tag=nativephp-plugins-provider` if the app has no NativeServiceProvider yet) to
get the exception pipeline from the first boot.

Until the headless commands ship in a core release, a scaffolded app needs the development core: add a path
repository for the local nativephp/mobile checkout and `composer require "nativephp/mobile:dev-<branch> as
4.99.0"`. If `native:run --json` prints nothing, you're on a released core without the flag.

## Preconditions & claiming a device

1. `php artisan native:devices --json` — list simulators/emulators. Prefer one that is already `booted`.
2. Record your target so every later command is unambiguous (and other agents don't collide):
   write `nativephp/agent-device.json` = `{"platform":"ios","udid":"<UDID>"}`. Every `--json` command
   resolves this automatically; you can also pass `--device=<udid>` explicitly.
3. If nothing is booted: iOS `xcrun simctl boot <udid>` (pick the newest iPhone); Android
   `php artisan native:emulator android`, then re-run `native:devices`.
4. For `native:open-url` to work, the app needs a deeplink scheme — set `NATIVEPHP_DEEPLINK_SCHEME=<scheme>`
   in `.env` (a rebuild bakes it in) when scaffolding a new app.

## The loop

```
edit code
└─ php artisan native:run <ios|android> <udid> --build=debug --no-tty --json   (allow 10 min for first build)
   ├─ ok:false, stage:build|install   → read .logTail (or native:tail); fix; rebuild
   ├─ ok:false, stage:verify          → app built but isn't running: boot fatal.
   │                                     check native:tail <os> --lines=100 and the devtools events; fix; rebuild
   └─ ok:true                          → note the current line count of nativephp/devtools/events.jsonl
      └─ php artisan native:screenshot <os> --json → Read the PNG → judge against the goal
         └─ exercise: php artisan native:open-url "<scheme>://<route>" <os> --json → screenshot each screen
            └─ any NEW lines in events.jsonl since the noted count? → an exception fired; read it; fix; rebuild
               └─ all screens verified, no new exceptions → done. Report with the screenshot paths.
```

`native:run --json` emits one JSON object on the last stdout line. On success:
`{"ok":true,"platform":...,"device":...,"appId":...,"buildType":"debug","pid":...,"buildLog":...,"durationMs":...}`.
On failure it carries `stage` (`validate|devices|build|install|launch|verify`), `error`, and — for build/install
— a `logTail`. Exit code is 0 only when `ok:true`.

### "Did it boot?" — the definition

Treat the app as booted-OK when: `native:run --json` returned `ok:true` **and** `native:status <os> --json`
shows `running:true` a few seconds later **and** no new `boot_fatal`/`exception` event appeared in
`nativephp/devtools/events.jsonl` since launch **and** the screenshot is not the red "Something went wrong"
error screen.

## Reading exceptions

Exception reporting comes from the **`nativephp/devtools` plugin**, which ships its own
`nativephp-devtools` skill — follow that for the detail (cursor discipline, wakeup modes, diagnosing by
failure class). The short version the loop depends on:

- Runtime exceptions, boot fatals, and PHP fatals stream to **`nativephp/devtools/events.jsonl`** (one JSON
  event per line, monotonic `seq`) while `native:watch` runs; read past your own cursor, never consume-once.
  `exception.app_frame` is the `file:line` in the user's own code — read that first.
- In discrete build-loop mode (repeated `native:run`, no `--watch`) the listener isn't running: run
  `php artisan native:devtools:pull <os> --json` after launch. It's session-scoped, so `pulled > 0` means a
  genuinely new failure.
- Exceptions also print as red lines in the `native:watch` console.

**Without the plugin**, none of that exists — fall back to `native:tail <os> --lines=100 --json` for the raw
`laravel.log`, plus screenshots to catch the red error screen.

## Exercising the app

For EDGE (native UI) screens, use `native:ui` (also from `nativephp/devtools`) — no coordinates, no drivers,
both platforms:

```bash
php artisan native:ui dump <os> --json          # what's on screen + what each handler fires
php artisan native:ui tap "Following" <os> --json      # press by visible text (or ref)
php artisan native:ui invoke "toggleLike(1)" <os> --json  # fire a handler expression directly
```

Dump → pick a target → tap/invoke → re-dump to confirm the state changed → screenshot to judge visuals. See
the `nativephp-devtools` skill for matching caveats and scope limits.

Fallbacks (and what to use when the plugin isn't installed): `native:open-url "<scheme>://<route>"` for
navigation (iOS sims show a one-time "Open in <App>?" confirmation for custom schemes); raw adb input
(`input tap/text/keyevent`, `uiautomator dump`) for Android system UI or webview-mode screens, which
`native:ui` does not cover.

## Hygiene

- Never use `--watch` for discrete build-loop iterations (it blocks). Run `native:watch` in the background only
  when you want live exception streaming.
- Never edit generated files under `nativephp/ios` or `nativephp/android`.
- One rebuild at a time per app repo; don't run two agents against the same repo.
- Remove any throwaway/debug code you added before reporting done.
- Never `simctl shutdown`/`erase` a simulator unless the user asked — other agents may share it.
