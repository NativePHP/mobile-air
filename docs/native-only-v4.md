# v4: Native-only — remove the full-app WebView

Decision (Shane + Simon, 2026-07-21): v4 ships without the app-as-WebView model.
The app IS native (EDGE). Web content exists only via the composable `<webview>`
component (PR #189 raw-slot). No escape hatch, no WEB_LEGACY boot, no v3-era
web-embedded chrome.

## KEEP (the `<webview>` component needs it)

- **Request-serving transport**: Android `PHPWebViewClient` request handling +
  asset serving; iOS `PHPSchemeHandler`. These become the *component's*
  transport, invoked only when a screen includes a webview element.
- Lazy WebKit/Chromium gating (`WebCookieMirror`, lazy creation): now per-component.
- `LaravelCookieStore` as cookie source of truth; jar-seeding on component creation.
- Persistent runtime, direct dispatch, element pipeline, hot reload (native mode),
  Jump TCP relay — all untouched.

## KILL — Android (`resources/androidstudio`)

- `BootPlanner` + manifest bake/refresh (boot is always native-direct; remove
  `native_routes`/`entry_mode` from bundle_meta + `booted()` dump in
  NativeServiceProvider + PreparesBuild/BuildIosAppCommand additions).
- `WebRenderer` as app-level renderer; MainScreen's webview branch; `pendingWebSwap`
  commit-gating; EXIT_WEB handling in `handleNativeSessionExit` (see PHP below).
- `startFirstContentWatchdog`'s WebView fallback → native error screen (below).
- Legacy chrome: `NativeUIState`-driven `NativeTopBar`/`BottomNavigationContent`/
  `NativeFab`/`SideDrawerContent` + `navigateWithInertia` + `x-native-ui` header
  processing in WebViewManager + `EdgeFunctions.Set`/`RenderEdgeComponents`
  middleware + `Edge::set()` web path.
- WebView JS glue used only in app-mode: `injectJavaScript` (window.Native),
  `AndroidBridge` drawer interface, safe-area/keyboard CSS injection,
  `NativeActionCoordinator`'s JS event half + `/_native/api/events` endpoint.
- Deep links: always native dispatch (cold + warm).

## KILL — iOS (`resources/xcode`)

- `BootPlanner.swift`/`BootState` web gating (always native); ContentView web
  branch (`NativeSideNavigation`/`WebViewLayoutContainer` app-mode hosting);
  orphaned-exit + EXIT_WEB handlers; `JumpWebViewSession` prototype;
  `navigateWithInertia`; watchdog WebView fallback → native error screen.

## KILL — PHP (`src/`)

- `Route::native` exit semantics: unresolved route / EXIT_WEB no longer
  redirects — render the native **error screen** (unknown route = native 404
  tree) or no-op with a logged warning. `NativeRouter` EXIT_WEB intent → error.
- `RenderEdgeComponents` middleware, `Edge::set/clear` web path,
  `NativeUIState`-era chrome payloads.
- **Precompiler warning** (do first, tiny): `<native:*>` tags in a non-native
  view → throw/log loudly with migration pointer. Kills today's silent
  HTMLUnknownElement failure mode.

## BUILD — native error screen (prerequisite)

Baked-in native tree (NOT PHP-rendered — PHP being broken is the failure case):
shown on boot failure (watchdog), dispatch crash, unresolved route. Android:
Compose fallback in MainActivity. iOS: SwiftUI fallback. Content: app name,
"something went wrong", error detail in debug builds only.

## Sequencing

1. Precompiler warning (independent, ship immediately).
2. Native error screens (both platforms) — replaces every WebView fallback.
3. Android removal; 4. iOS removal; 5. PHP removal + test updates;
6. Re-run full bench matrix (numbers should only improve — less code, no planner);
7. Coordinate with Simon's #189 so the component lands on the cleaned transport;
8. Migration notes for v4 release (web-app pattern removed; `<webview>` component
   is the replacement).

## Status

- Branch: `feat/native-only-v4` (off feat/native-first-boot incl. all fixes).
- Nothing removed yet — this spec is the work order.
