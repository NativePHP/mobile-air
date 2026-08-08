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

Runtime exceptions on device stream to **`nativephp/devtools/events.jsonl`** (one JSON event per line) whenever
`native:watch` is running (it starts the listener automatically). Each event has
`exception.class`, `exception.message`, `exception.app_frame` (the `file:line` in the user's own code), and a
capped `trace`. This is the source of truth — read past your last-seen line count; don't consume-once.

- Human view while developing: exceptions also print as red lines in the `native:watch` console.
- On demand: `native:tail <os> --lines=100 --json` for the raw `laravel.log`; `native:devtools:pull <os> --json`
  to merge any events the device spooled while the listener was down. Pull is session-scoped by default (stale
  events from previous sessions are filtered, reported as `filtered_stale`) — trust `pulled > 0` as "new failure
  happened", no timestamp triage needed. Use `--purge` to clear the device spool after a pull, `--all` to see
  everything.
- If the `nativephp-devtools` MCP server is registered, prefer its tools (`get_exceptions`,
  `await_next_event`, `get_screenshot`, `tail_app_logs`, `device_info`) over shelling out.

Note: in discrete build-loop mode (repeated `native:run`, no `--watch`) the listener isn't running, so use
`native:devtools:pull` after launch to collect device-side events, or run `native:watch` in the background.

## Exercising the app (v1 limits)

v1 verification is **screenshots + deep links + logs/events**. Navigate with
`native:open-url "<scheme>://<route>"` and screenshot each screen.

- iOS simulators show a one-time "Open in <App>?" confirmation for custom-scheme deep links opened via
  `simctl openurl`; there is no blessed CLI tap. Prefer verifying screens that are reachable from the app's
  launch/start URL, or set `NATIVEPHP_START_URL` and relaunch to land directly on a screen.
- Android only: you may drive UI with raw adb — `adb -s <serial> shell input tap X Y`, `input text '...'`,
  `input keyevent 66`, and `adb -s <serial> exec-out uiautomator dump` to find coordinates.

## Hygiene

- Never use `--watch` for discrete build-loop iterations (it blocks). Run `native:watch` in the background only
  when you want live exception streaming.
- Never edit generated files under `nativephp/ios` or `nativephp/android`.
- One rebuild at a time per app repo; don't run two agents against the same repo.
- Remove any throwaway/debug code you added before reporting done.
- Never `simctl shutdown`/`erase` a simulator unless the user asked — other agents may share it.
