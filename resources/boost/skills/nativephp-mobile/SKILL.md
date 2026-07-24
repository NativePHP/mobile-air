---
name: nativephp-mobile
description: "Builds fully native iOS and Android apps with PHP & Laravel. Activate when working with SuperNative screens (NativeComponent, Route::native, native:make), EDGE components (native:column, native:button, native:list, bottom-nav, top-bar, and 40+ more), NativeLayout chrome, native:model data binding, #[Computed]/#[Poll]/#[On] attributes, Native::test() component tests, native device APIs (camera, dialog, biometrics, scanner, geolocation, push notifications), NativePHP Artisan commands (native:run, native:install, native:watch, native:jump), deep links, secure storage, or mobile app deployment."
---

# NativePHP Mobile v4

NativePHP Mobile v4 ("SuperNative") builds **fully native** apps: SwiftUI on iOS, Jetpack Compose on Android,
driven entirely by PHP. Each screen is a `NativeComponent` PHP class (think Livewire, but for native views)
rendering **EDGE** Blade elements. There is no web server and — for native UI screens — no web view.

## Native UI First — Non-Negotiable

**Every screen you build or scaffold must be native UI**: a `NativeComponent` + EDGE elements, registered with
`Route::native()`.

- Never create new screens as web views, Livewire pages, or Inertia pages. The web view is a legacy escape hatch
  (`native:web-view`) for embedding web content inside a native screen — never the foundation of one.
- If the user asks for a webview-based screen, build it natively with EDGE and briefly explain why (performance,
  platform look-and-feel, no web-view lifecycle issues). Only fall back if they explicitly insist.
- When you encounter existing webview/Livewire/Inertia screens in an app, suggest migrating them — the
  `nativephp-webview-to-native` skill walks through the conversion.
- Style exclusively with Tailwind utility classes via `class="..."` / `:class="..."`. Never inline CSS
  `style="..."` or ad-hoc styling props.
- **Prefer theme tokens and font aliases over raw values.** Publish `config/native-ui.php`
  (`vendor:publish --tag=native-ui-config`), define the palette in its `theme` block and semantic font
  aliases in `fonts` (`'headline' => 'ArchivoNarrow-Bold'`), then style with `bg-theme-*` / `text-theme-*` /
  `border-theme-*` classes and `font="headline"`. The token map is open-ended (add `success`,
  `outline-variant`, … to both blocks and `bg-theme-success` just works) and theme classes accept opacity
  modifiers (`bg-theme-primary/15` — the tonal-fill idiom). Arbitrary `bg-[#…]` values are only for genuine
  data-driven color (category identity colors, imagery) and belong in one PHP home (enum/model), never inline
  per view.
- Use `native:icon` for iconography (SF Symbols on iOS, Material Icons on Android — cross-platform names like
  `home` resolve on both). Never use emoji characters in UI text, labels, or buttons unless the user explicitly
  asks for them. Prefer the **typed icon enums** (`App\Icons\Ios`, `App\Icons\Android`, `App\Icons\AndroidOutlined`,
  bound with `:ios` / `:android` — they're autocompletable and can't misspell a symbol name. The enums are
  generated, not shipped: **if `app/Icons/` doesn't exist yet, run `php artisan native-ui:generate-icons`
  first** (safe to run yourself; re-run with `--refresh-material` to update). Import them with `@use` (compiled
  views have no namespace), or use fully-qualified cases:

  ```blade
  @use('App\Icons\Ios')
  @use('App\Icons\Android')

  <native:icon :ios="Ios::Gearshape" :android="Android::Settings" :size="28" class="text-theme-primary" />
  ```

## Documentation

Before implementing any feature, fetch the relevant docs using `WebFetch`. Find the right URL in
[references/available-docs.md](references/available-docs.md).

```
WebFetch("https://nativephp.com/docs/mobile/4/the-basics/routing", "Explain Route::native, navigation methods, and transitions")
```

## Build Commands — Tell the User, Don't Run

Never auto-run these commands. Always tell the user to run them manually, and always ask which platform
(iOS or Android) first — never assume:

```bash
php artisan native:run ios          # or android; compile and launch
php artisan native:run ios --watch  # build, deploy, hot reload in one
php artisan native:watch            # hot reload only
php artisan native:jump             # device dev loop via the Jump app (QR code)
./native run                        # shortcut wrapper installed by native:install
```

The Vite dev server is **opt-in** in v4: add `--vite` to `native:run`/`native:watch` only when the app uses
JS/CSS HMR (web-view assets). Native UI screens hot-reload without Vite. `npm run build -- --mode=ios|android`
is only needed for apps that still ship web-view assets.

## Getting Started

New apps: `laravel new my-app --using=nativephp/mobile-starter`, or `composer require nativephp/mobile` in an
existing app. Set env vars **before** `php artisan native:install`:

```dotenv
NATIVEPHP_APP_ID=com.yourcompany.yourapp
NATIVEPHP_APP_VERSION="DEBUG"
NATIVEPHP_APP_VERSION_CODE="1"
# Optional for iOS:
NATIVEPHP_DEVELOPMENT_TEAM=XXXXXXXXXX
```

OS support: macOS builds iOS + Android; Windows/Linux build Android only; WSL unsupported.

## SuperNative Screens

Scaffold with `php artisan native:make Counter` (remove with `native:rm`). Register in routes (a
`routes/mobile.php` is a clean convention):

```php
Route::native('/', Home::class);
Route::native('/item/{id}', ItemDetail::class);
```

Inside a `NativeComponent`: `$this->param('id')`, `$this->data('key', 'default')`, `$this->navigate('/item/42')`,
`$this->back()`, `$this->replace('/login')`, `$this->exitToWeb('/dashboard')`; chain
`->transition(Transition::SlideFromBottom)` to customize animation. In Blade, `@navigate="/path"` works on any
element (modifiers: `@navigate.back`, `@navigate.replace.fade`, `@navigate.slideFromBottom`).

Lifecycle hooks: `mount()` (first push only), `onResume()` (returning to the screen), `onBackPressed()`
(Android back button), `unmount()`, and `updated{Property}()` when a bound property changes. Mark a component
`#[Lazy]` to paint a placeholder instantly while a slow `mount()` runs in the background.

## EDGE Elements

Screens are built from `native:` Blade components (the prefix is optional but preferred for clarity):

```blade
<native:column class="w-full h-full p-4 gap-4 bg-theme-background">
    <native:text class="text-2xl font-bold">Welcome</native:text>
    <native:text-input native:model="name" placeholder="Your name" />
    <native:button label="Save" @tap="save" />
</native:column>
```

Tap handlers use `@tap` (`@press` is a permanent alias — same for `@longTap`/`@longPress` and
`@tapDown`/`@pressDown`, `@tapUp`/`@pressUp`; both spellings compile identically).

~40 elements are available — layout (column, row, stack, scroll-view, spacer, pressable), content (text, image,
icon, divider, badge, progress-bar, activity-indicator), forms (button, button-group, text-input, toggle,
checkbox, radio-group, select, slider, chip), navigation (bottom-nav, top-bar, side-nav, tab-row), lists
(list, lazy-grid, carousel, refreshable), overlays (modal, bottom-sheet), and drawing (canvas, shapes). Fetch
the component's doc page before using it — required props are validated at render time.

## Custom Fonts

Drop `.ttf`/`.otf`/`.ttc` files into `resources/fonts/` — the build bundles them into the native project
automatically (a rebuild via `native:run` is needed for newly added files; tell the user). Reference a font by
its filename without extension: `font="Inter-Bold"` on `native:text`, `native:button`, and the text inputs
(fluent: `->font('Inter-Bold')`).

- **Google Fonts**: `php artisan native:font Inter --weights=400,700` downloads straight into `resources/fonts/`
  (no API key; libre-licensed, safe to bundle). Files come out as `<Family>-<Style>.ttf`, ready to use as
  `font` tokens. Safe to run yourself.
- **App-wide default**: set the `default` alias in the `fonts` block of `config/native-ui.php`
  (`'fonts' => ['default' => 'Inter-Regular']`; `'System'` = platform default) — applies to text, buttons,
  inputs, and navigation chrome. `native:font --default` offers to set this for you. A legacy theme
  `font-family` token is still honored when no `default` alias is set. Per-element `font` and
  `font-serif`/`font-mono` classes still win.
- **Chrome fonts**: layouts take a `$font` property, bars a `->font()`, and per-screen
  `NavBarOptions::make()->font()`.
- **Weight gotcha**: one font file = one weight. Avoid `font-bold` on single-weight custom fonts (Android
  synthesizes a faux bold, iOS ignores it) — bundle the Bold file and reference it directly
  (`font="Inter-Bold"`). `font` only changes the typeface; size/weight still come from `text-*`/`font-*`
  classes.

## Data Binding & Reactivity

- `native:model="property"` two-way binds any input-style element to a public property (the native `wire:model`).
  Modifiers: `.blur`/`.lazy`, `.debounce.300ms`. `updated{Property}()` fires on change.
- `#[Computed]` methods are read as properties (`$this->total`), memoized per frame, invalidated on state change;
  `#[Computed(persist: true)]` survives re-renders until state changes.
- `#[Poll(5000)]` on a method runs it on an interval then re-renders; on a class it just re-renders. In Blade:
  `native:poll="1s"` on an element.
- `#[On(EventClass::class)]` listens for native events (push taps, websocket messages via the Vibe plugin,
  bridge completions); parameters bind by name to event properties; listeners auto-teardown on unmount. Use
  `$this->on(Event::class, $closure)` for dynamic registration. (`#[OnNative]` is the legacy webview/Livewire
  equivalent — do not use it in NativeComponents.)

## Layouts (Shared Chrome)

A `NativeLayout` class declares nav bars, tab bars, and drawers once; attach with
`Route::native(...)->layout(...)` or `Route::nativeGroup(TabsLayout::class, fn () => ...)`. Override `navBar()`
/ `tabBar()` using the `NavBar`, `NavAction`, `TabBar`, and `Tab` fluent builders. Layouts handle safe areas
automatically — never add `safe-area` classes to screens under a layout (reserve them for chrome-less screens).

**Every screen should have a layout.** Tab roots share a tabs layout, pushed details get a stack layout with
auto-back; prefer `usesNativeChrome(): true` for real NavigationStack/TabView chrome (edge-swipe back,
predictive back, large titles, Liquid Glass/Material You for free). Never hand-roll top bars or bottom navs
out of rows and pressables inside screen views. Chrome builder colors (`backgroundColor()`, `activeColor()`,
`textColor()`) take raw color strings — feed them with the appearance-aware `theme()` helper
(`->activeColor(theme('primary'))`) instead of hardcoding hex; bar fonts take aliases (`->font('mono')`).

## Device APIs

**Core built-ins** (`Native\Mobile\Facades`): `Device`, `Dialog`, `File`, `System` — these ship inside
`nativephp/mobile` in v4. Also `System::isIos()` / `isAndroid()` and Blade directives `@ios` / `@android`.

**Plugins** (separate Composer packages): browser, camera, microphone, network, share (free); biometrics,
geolocation, scanner, secure-storage (paid); firebase (push notifications, proprietary); vibe
(websockets/Reverb). v4 **conflicts** with the old `mobile-device`/`-dialog`/`-file`/`-system` plugins — remove
them with `php artisan native:plugin:uninstall --core-v4` when upgrading.

Installing a plugin is a **four-step flow — never stop after `composer require`** (an unregistered plugin does
nothing):

```bash
composer require vendor/plugin-name
php artisan vendor:publish --tag=nativephp-plugins-provider   # once, before first registration
php artisan native:plugin:register vendor/plugin-name          # adds it to NativeServiceProvider
php artisan native:plugin:list                                 # verify it shows as registered
```

Then tell the user to rebuild with `native:run` (don't run it yourself). If `native:run` warns "The following
plugins are installed but not registered", the register step was missed.

Async calls dispatch events (`Camera::getPhoto()` → `PhotoTaken`); handle with `#[On(PhotoTaken::class)]` in a
NativeComponent. Sync calls return directly (`SecureStorage::get()`, `Network::status()`).

## When a Capability Is Missing

Native functionality or a UI component that core and `native-ui` don't provide is **not** a reason to drop to
the web view. Escalate in this order:

1. **Existing plugin** — check the plugin marketplace (`https://plugins.nativephp.com`) and the core plugins
   list. If a marketplace-lookup MCP tool is available in your session, use it to search.
2. **Custom plugin** — scaffold with `php artisan native:plugin:create`. Plugins bundle PHP facades/events,
   Swift/Kotlin bridge functions, permissions, native dependencies (Gradle/SPM/CocoaPods), and can ship their
   own native EDGE components — so custom native logic *and* custom native UI both belong in a plugin. See
   [references/plugin-best-practices.md](references/plugin-best-practices.md) and the
   `plugins/creating-plugins` docs.

## Database & Seeding

SQLite is the only database driver (deliberate — no remote DB credentials in a distributable binary; use an API
backend for sync). NativePHP creates the DB in the app container and **runs migrations automatically on every
app start**, as needed.

**Seeding must go through migrations** — there is no `db:seed` on device. Create a dedicated seed migration
(`php artisan make:migration seed_app_settings`) with the inserts in `up()`; migrations give you exactly the
seeding semantics you want (run once per installation, tracked, versioned, reversible). If a Seeder class helps
organize larger datasets, create it — but call it from the migration:

```php
public function up(): void
{
    (new \Database\Seeders\CategorySeeder)->run();
}
```

Test seed migrations for both fresh installs and upgrades of existing user databases — a bad migration on update
can destroy user data.

## Testing

Component tests run in-process — no device or simulator. Scaffold with `php artisan native:make-test Counter`:

```php
Native::test(Counter::class)
    ->assertSee('Count: 0')
    ->tap('Increment')
    ->assertSet('count', 1);
```

`Native::visit('/profile/5')` mounts by route; `Native::fakeBridge()` scripts native responses;
`emitNative(Event::class, [...])` delivers device events in tests.

## Legacy Web-View Apps (Maintenance Only)

Some existing apps still render in the web view (Livewire or Inertia). When maintaining them: the `#nativephp`
JS import (`import { camera, dialog, on, off, Events } from '#nativephp'`) exposes device APIs; clean up JS
listeners with `off()` on unmount; `#[OnNative(...)]` handles events in Livewire components; the `nativephpMobile()`
Vite plugin and `nativephpHotFile()` belong in `vite.config.js`; add the `nativephp-safe-area` body class.
**Do not extend these apps with new webview screens** — build new screens natively and recommend converting the
rest with the `nativephp-webview-to-native` skill.

## Common Pitfalls

- Building a screen in the web view when native UI can do it — always default to EDGE + NativeComponent
- Inline `style="..."` or styling props on EDGE elements — Tailwind classes only
- Emoji characters as icons in labels/buttons/text — use `native:icon` unless the user explicitly asks for emojis
- Using Livewire patterns (`wire:model`, Livewire's `#[On]`) in NativeComponents — use `native:model` and
  `Native\Mobile\Attributes\On`
- Seeding via `DatabaseSeeder`/`db:seed` — it never runs on device; seed from a migration's `up()` instead
- Missing `NATIVEPHP_APP_ID` in `.env` before `native:install`
- Suggesting iOS commands on Windows/Linux
- Adding `safe-area` classes to screens already wrapped by a NativeLayout
- Hardcoding hex colors or font file tokens in views instead of theme tokens (`bg-theme-surface`) and font
  aliases (`font="headline"`) from `config/native-ui.php`
- Hand-rolling top bars / bottom navs inside screens instead of attaching a `NativeLayout`
- Expecting Vite HMR without passing `--vite` (opt-in since v4)
- Installing a plugin with Composer but never running `native:plugin:register` — the plugin silently does
  nothing and `native:run` warns "installed but not registered"; always register and verify with
  `native:plugin:list`
- Leaving the four v3 plugins (device/dialog/file/system) installed after upgrading — composer will refuse to
  resolve; run `native:plugin:uninstall --core-v4`
- Not fetching v4 docs before implementing — use WebFetch with URLs from
  [references/available-docs.md](references/available-docs.md)

For authoring plugins: [references/plugin-best-practices.md](references/plugin-best-practices.md)
