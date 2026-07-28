---
name: nativephp-v3-to-v4-upgrade
description: "Upgrade a NativePHP Mobile v3 app (Livewire/Volt/Flux in a web view) to v4 native SuperNative EDGE UI. Covers the composer/package moves the docs don't mention, the plugin rename, fonts and theme-push timing, the silent-failure layout traps, testing, and cutover. Activate when a project is on nativephp/mobile v3, when composer requires nativephp/native-ui, when native screens render as system font or blank/overlapping boxes, or when the user asks to upgrade/migrate to v4."
---

# NativePHP Mobile v3 → v4 Upgrade

Battle-tested from migrating WhipRate mobile (18 screens, 21 Livewire components, full Flux Pro UI) to
100% native EDGE with 700+ passing tests. Follow the phases in order.

**The defining property of v4 debugging: almost every failure is SILENT.** Unsupported classes are
dropped, unsized nodes render nothing, a theme push that arrives too late leaves the system font in
place — none of it errors. When something looks wrong, assume "dropped silently" before "styled
wrong", and verify against source rather than reasoning about it.

## Phase 0 — Setup (get this wrong and everything fails)

### The package moved, and the marketplace lags

The UI component library was **renamed**, and this is not in most docs:

| v3 / older | v4 |
|---|---|
| `nativephp/native-ui` | `nativephp/mobile-ui` |
| `Nativephp\NativeUi\` | `Native\Mobile\UI\` |
| type `nativephp-ui-plugin` | type `nativephp-plugin` |

Three composer facts that block a naive `composer require`:

1. **`plugins.nativephp.com` may only publish a stale branch.** If `composer show nativephp/mobile-ui`
   lists one old dev branch, add a **vcs** repository for `https://github.com/NativePHP/mobile-ui`
   and list it FIRST — composer repos are canonical, so the first one providing the package wins.
2. **`nativephp/mobile` lives in the `mobile-air` repo** (`https://github.com/NativePHP/mobile-air`),
   not `NativePHP/mobile`.
3. **`mobile-ui` requires `nativephp/mobile: ^4.0`**, and mobile's `dev-main` carries no
   `branch-alias`, so the solver rejects it. Use an inline alias:
   `"nativephp/mobile": "dev-main as 4.0.99"`.

`mobile-ui` also raises the **iOS minimum to 18.2**. Check your deployment target before upgrading.

### Register the plugin — installing is not enough

`php artisan native:plugin:register nativephp/mobile-ui`, verify with `native:plugin:list`. The app's
`app/Providers/NativeServiceProvider::plugins()` imports the provider **by FQCN**, so the namespace
change has to be applied there too. Device builds then need a user-run `native:run` (never run build
commands yourself — always ask which platform first).

Without the UI plugin, `nativephp/mobile` ships the EDGE runtime but NOT the component library
(text/image/button/list/inputs, bottom-sheet, layout builders, `Theme`, `config/native-ui.php`, icon
tooling) — every element throws "Unknown native element type".

### Removal ordering for Livewire packages

Strip `VoltServiceProvider` (and any Livewire-touching provider) from `bootstrap/providers.php`
**before** `composer remove livewire/flux-pro livewire/flux livewire/volt livewire/livewire` —
`composer remove` triggers `package:discover`, which boots the app and fatals on missing classes.
Also delete the `flux-pro` entry from composer.json `repositories`.

### Trust source over docs

Package READMEs and bundled skill docs can be **ahead of the shipped code** — v4 docs referenced
`php artisan native:font` months before any package shipped it. The truth is
`vendor/nativephp/mobile-ui/src/`, `vendor/nativephp/mobile/src/Edge/`, and the renderers under
`resources/ios/` + `resources/android/`. If a documented command is missing, check which package it
belongs to before assuming your install is broken.

## Phase 1 — Understand (before writing any screen)

Produce three docs (parallel agents if orchestrating):
- **API reference from vendor source**: element catalog, `TailwindParser` whitelist, `NativeComponent`
  lifecycle, routing macros, layout builders, testing API.
- **Screen inventory**: per routed screen — state seeding (exact request/cache/DTO classes), every
  action, events, navigation, UI outline, Livewire mechanics needing translation, covering tests.
  Document non-routed embedded components (modals, permission managers) under the parent screens that
  must absorb them.
- **Design plan**: trace the real palette/typography out of the Tailwind CSS into a
  `config/native-ui.php` theme (light+dark for every role), plus tab/stack layout designs.

## Phase 2 — Scaffold (foundation before fan-out)

Order: publish native-ui config → fill theme+fonts → `native-ui:generate-icons` → write `NativeLayout`
classes (tabs / stack / form-stack / guest; `theme()` for all chrome colors, no hex) → rewrite
`routes/web.php` with `Route::native()`/`Route::nativeGroup()` preserving every URI and route name →
generate ONE component with `native:make` to prove path conventions → write a `conventions.md`
contract doc (namespaces, paths, layout assignments, theme tokens, gotchas) that all converters read.

Route order matters: static segments before `{param}` siblings (`/cars/create` before `/cars/{id}`).
Routes may reference not-yet-existing classes — registration is string-based, the app still boots.

### Fonts — the single most confusing part of the upgrade

The webview pulled fonts from a CDN. Native cannot. The whole custom-font path lives in
**`mobile-ui`**, not `nativephp/mobile`:

1. `php artisan native:font Inter --weights=400,600,700,900 --italic` downloads Google Fonts straight
   into `resources/fonts/`. **This command ships in `mobile-ui`.** Looking for it in
   `nativephp/mobile` is a dead end no matter how current that package is.
2. `CopyFontsCommand` (the plugin's `copy_assets` build hook) bundles `resources/fonts/*` into each
   native project at build time. Fonts only appear after a real `native:run`.
3. Register semantic aliases in `config/native-ui.php` `fonts` and use them in views (`font="headline"`).
   The **`default` alias is the app-wide font and supersedes the legacy `font-family` typography
   token** when both are set.
4. `--default` sets the app-wide alias — but it uses the LAST file it downloaded, which is often an
   italic. Check the value it wrote.

**Two traps that both present as "my font isn't working" (text renders as SF Pro / Roboto, upright):**

- **The theme push can fire before the bridge is reachable.** `Theme::pushToNative()` no-ops when
  `nativephp_call` isn't available, and `NativeUIServiceProvider` pushes from its own `boot()`. If it
  no-ops, the native side keeps its defaults — **alias map empty, font family "System"** — so every
  `font="…"` silently falls back. Re-push once the container is fully booted:
  ```php
  // app/Providers/NativeServiceProvider::boot()
  $this->app->booted(fn () => Theme::pushToNative());
  ```
- **A `font=` attribute needs a matching weight class.** Without `font-extrabold` / `font-semibold`
  alongside it, the face collapses to the regular weight.

**Colors working is NOT evidence the theme push works.** `bg-theme-*` classes are resolved to literal
hex per node in PHP, so colors look correct even when the bridge push never lands. That mismatch is
exactly what makes this read as a font-config problem instead of a theme-push one.

## Phase 3 — Shared partials before screens

Convert shared cards/skeletons/buttons/sheets to `resources/views/native/partials/` FIRST, with a
`PartialsTest.php` harness proving each renders. Document the host contract (required vars, action
names the host screen must implement, refs for tests) in conventions.md. Screen converters then
`@include('native.partials.X')` — parent public props propagate into includes automatically.

## Phase 4 — Convert screens (parallelizable, one agent per screen)

Strict file-ownership: each converter owns its component class, view, screen sub-partials, and test
file; NEVER edits routes, config, layouts, shared partials, or the data layer. Blockers get reported,
not fixed inline. Keep the entire data layer (Saloon requests, caches, DTOs, services) untouched.

### Livewire → EDGE translation

- **`native:model` is LIVE by default** — the opposite of Livewire 3. Add `.blur`/`.debounce.Xms`
  where the old code used deferred binding.
- **No built-in `validate()`**: manual `Validator` + `public array $errors` + `@nativeError`.
- **No query strings on native routes**: `?foo=1` becomes navigation data read via `$this->data('foo')`
  in `mount()`.
- `wire:init` → `#[Lazy]` + `placeholder()`; `x-intersect` infinite scroll → `@endReached` (throttle
  it); pull-to-refresh → `refreshable`/`@refresh`; `wire:poll` → `#[Poll(ms)]`; flux modals →
  `native:bottom-sheet`/`native:modal`; teleported nav actions → per-screen
  `navigationOptions`/`bottomBar`; `hideTabBar` → `$hidesTabBar`.
- **Traits calling Livewire's `$this->redirect()`**: add a screen-local `redirect()` proxy forwarding
  to `navigate()` rather than editing the shared trait mid-migration; port it properly in cleanup.
- **Icons**: the generated icon enums via `:ios`/`:android`; a raw SF Symbol / Material name string is
  accepted when an enum case is missing.
- **Event directives interpolate**: `@tap="{{ $action }}({{ $id }})"` works — enables parametrizable
  partials.
- Missing native capability (e.g. HEIC conversion the webview did)? Flag for a **plugin** decision —
  never fall back to the web view.

### Layout traps that render NOTHING and never error

These cost the most time. All verified from renderer source.

1. **An empty `<native:column>` paints nothing.** `NativeUIColumnRenderer` opens with
   `if node.children.isEmpty { Color.clear }` — background, width and height all discarded. Use
   **`<native:rect />`** (self-closing) for any solid fill or scrim band; it renders
   `Rectangle().fill(...)` from `style.bg_color`. `native:circle` / `native:line` are the equivalents
   for other shapes.
2. **Absolute children are ANCHORED, never stretched.** `NativeUIStackLayout` and
   `FlexContainer.placeAbsolute` place a child at its own measured size. Opposing insets do NOT imply
   a size the way they do in CSS, so `absolute inset-0` yields an intrinsic-sized child at top-left —
   nothing at all for a rect. To fill a stack: `absolute top-0 left-0 w-full h-full`.
3. **A zero inset means "no anchor on that edge."** The packed node struct has no spare byte to
   distinguish an unset edge from an explicit `0`, so `bottom-0` pins to the **TOP** and `right-0`
   anchors **LEFT**. Use the smallest real value (`bottom-px`) when you want flush. Negative insets
   deliberately overhang (`-right-8` bleed).
4. **Aspect ratio goes on the STACK, not the image.** A remote image's natural size is tiny, so
   `<native:stack class="w-full"><native:image class="aspect-[3/4]">` leaves the stack collapsed and
   the image overflows every sibling below it. Put `aspect-[3/4]` on the stack and give the image
   `w-full h-full`.
5. **Unsupported Tailwind classes are dropped in silence.** Verify before assuming:
   `TailwindParser::parse('the-class')` returning `[]` means unsupported. Known gaps: `blur-*`,
   `scale-*`, per-corner radii (`rounded-t-xl` — only uniform keys parse), `max-w-*` / `min-w-*`.
   Gradients (`bg-gradient-to-*` / `bg-linear-to-*` with `from`/`via`/`to`) and `inset-*` ARE
   supported as of 2026-07.
6. **`leading-*` maps to SwiftUI `.lineSpacing`** — space BETWEEN lines. It is a no-op on single-line
   text, and iOS cannot tighten below the font's natural line height. Adjust padding instead.
7. **Image `src` must be an absolute on-device path** — `public_path('img/x.png')`. A relative
   `img/x.png` is handed to AsyncImage/Coil, which can't resolve it, and renders blank.

### Tests are the safety net

Port every legacy Livewire test to `Native\Mobile\Testing\Native::test()`/`Native::visit()` with
`FakeBridge` + Saloon `MockClient`. Cover happy/failure/weird paths (401→login redirect, offline,
empty feeds). Add `ref` attributes to interactive elements for test targeting.

Wire-format facts that make assertions land first time:
- The test harness renders through `createElement`, a **different code path from the device's
  streaming collector**. A prop can work in tests and be missing on device, or vice versa.
- Fills live at **`style.bg_color`** as `#AARRGGBB` — there is no `props.bg`.
- `uppercase` is applied by the renderer, so the `text` prop keeps its **original case**.
- `layout.position` is `[top, right, bottom, left]`; `layout.position_type === 1` means absolute.
- `assertElement($type, $matcher)` + `tree()` for structural assertions; guard `position` with an
  `is_array`/`count` check, since not every node carries one.

## Phase 5 — Integrate + cutover

1. Integrator: fix reported blockers, verify every route resolves (`NativeRouter->resolve()` returns
   `['class','layout','params']`), run the full native suite plus untouched data-layer suites, and
   grep native views for hex literals / non-whitelist classes / leftover `wire:`/`flux:` syntax.
2. Only once green: delete `app/Livewire`, `resources/views/livewire`, `resources/views/flux`, legacy
   `resources/views/components`, and old Livewire test dirs. Rewrite generic smoke tests
   (`assertSeeLivewire` → route-resolution or `Native::visit()->assertScreen()`).
3. `composer dump-autoload`, full suite, `vendor/bin/pint --dirty`, `php artisan native:validate`.
4. Remind the user to rebuild — plugin registration, plugin Swift/Kotlin sources AND bundled fonts all
   compile in at build time.

## Patching the framework during an upgrade

Local path checkouts of `mobile` / `mobile-ui` are common on this path. If you add a style utility,
it must travel **three layers**, and the collector has **four** call sites — missing one produces the
classic "works in tests, missing on device" split:

- `mobile/src/Edge/TailwindParser.php` — class → attrs. A feature spanning several classes (like
  gradients) needs a **deep-merge** branch in `parse()`, as `dark` has; a flat `array_merge` lets the
  last class clobber the earlier ones.
- `mobile/src/Edge/NativeElementCollector.php` — attrs → wire. Sites: `openStreaming`,
  `leafStreaming` (each with a builtin and a plugin branch), and **`createElement`**, which is what
  the test harness uses.
- `NodeStyleModifier.swift` / `NodeModifiers.kt` — the single choke point where every node's
  background is painted; wiring there makes a utility work on any element.

The node is a **packed 160-byte binary struct** with fixed offsets, so the style block cannot take
variable-length data — ride the **props bag** instead (`dark_bg_color` does). Unknown prop keys are
fine: the encoder falls back to an inline string for anything outside the interned `PropKey` table.

## Orchestration notes (multi-agent)

- The `conventions.md` contract doc is what makes parallel conversion safe — write it before fan-out,
  append the partials contract to it.
- Effort scaling: complex screens (multi-step forms, camera, geolocation) get high effort; simple
  lists don't.
- Workflow `args` may arrive as a JSON string — guard with
  `typeof args === 'string' ? JSON.parse(args) : args`.
- Cache-resume: fix a workflow script bug and resume with the same run ID; completed agents replay free.
