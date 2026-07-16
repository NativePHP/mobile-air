---
name: nativephp-webview-to-native
description: "Converts legacy NativePHP web-view screens (Livewire, Inertia, Blade-over-WebView) into fully native SuperNative screens (NativeComponent + EDGE elements). Activate when a NativePHP app contains web routes, Livewire components, or Inertia pages rendering in the web view, when the user asks to migrate/modernize/go native, or when NATIVEPHP_START_URL points at a web route."
---

# Convert Web-View Screens to Native UI

NativePHP Mobile v4 renders fully native UI (SwiftUI/Compose) from PHP. Web-view screens are legacy. This skill
converts them — incrementally, one screen at a time — into `NativeComponent` classes rendering EDGE elements.

Always activate the `nativephp-mobile` skill alongside this one, and fetch the relevant v4 docs
(`https://nativephp.com/docs/mobile/4/**`) before converting each screen — especially
`the-basics/routing`, `the-basics/layouts`, `digging-deeper/data-binding`, and the doc page for every EDGE
element you use.

## Strategy: Incremental, Screen by Screen

The web view remains available during migration, so you never need a big-bang rewrite:

1. **Inventory** every screen: web routes (`routes/web.php`), Livewire components (`app/Livewire/`), Inertia
   pages (`resources/js/Pages/`). Note which use device APIs, forms, lists, and navigation chrome.
2. **Order the work**: start with simple, high-traffic screens (lists, detail views, settings); leave complex
   web-dependent screens (rich text editors, embedded maps/charts) for last.
3. **Convert one screen**, register it with `Route::native()`, test it, then move to the next. Native screens
   can jump to remaining web screens with `$this->exitToWeb('/legacy-route')`, and web screens can link into
   native routes — the two stacks coexist.
4. **Finish**: when all screens are native, remove `NATIVEPHP_START_URL` web entries, unused Livewire/Inertia
   scaffolding, the `nativephp-safe-area` body class, and drop `--vite` from dev workflows if no JS remains.

## Per-Screen Recipe

1. Scaffold: `php artisan native:make ScreenName` → `app/NativeComponents/ScreenName.php`.
2. Move state: public properties transfer as-is from the Livewire component (or from Inertia page props into
   properties loaded in `mount()`).
3. Register the route: `Route::native('/items/{id}', ItemDetail::class)` (a `routes/mobile.php` file is a clean
   convention). Read params with `$this->param('id')`.
4. Convert the template using the mapping tables below. Style with Tailwind utility classes via `class="..."`
   only — never inline `style="..."`.
5. Extract chrome: nav bars, tab bars, and drawers move out of the template into a `NativeLayout` class attached
   via `->layout(...)` or `Route::nativeGroup(...)`. Do not add `safe-area` classes to screens under a layout.
6. Replace JS device calls (`#nativephp` imports) with PHP facades + `#[On]` event listeners.
7. Write a test: `php artisan native:make-test ScreenName`, assert with `Native::test()` / `Native::visit()`.
8. Tell the user to run `php artisan native:run <platform> --watch` to verify — never run it yourself, and ask
   which platform first.

## Livewire → SuperNative Mapping

| Livewire | SuperNative |
|---|---|
| `class Foo extends Component` | `class Foo extends NativeComponent` |
| `Route::get()` + view / `Route::livewire()` | `Route::native('/path', Foo::class)` |
| `wire:model` / `.blur` / `.debounce.300ms` | `native:model` / `.blur` / `.debounce.300ms` |
| `wire:click="save"` | `@press="save"` |
| `wire:submit` | `@submit` on the input / `@press` on the button |
| `wire:poll.5s` | `#[Poll(5000)]` on a method, or `native:poll="5s"` |
| `#[Computed]` (Livewire) | `#[Computed]` (`Native\Mobile\Attributes\Computed`) |
| `#[On]` (Livewire) / `#[OnNative]` | `#[On(Event::class)]` (`Native\Mobile\Attributes\On`) |
| `mount()`, `updatedFoo()` | Same names — plus `onResume()`, `onBackPressed()`, `unmount()` |
| `redirect()` / `<a href>` | `$this->navigate()`, `$this->replace()`, `$this->back()`, or `@navigate` |
| `@if` / `@foreach` / Blade logic | Unchanged — it's still Blade |
| Alpine.js state/toggles | Component properties + `@press`, or `SharedValue` for gesture-driven UI |

## HTML → EDGE Mapping

| Web | EDGE |
|---|---|
| `<div>` (vertical flow) | `<native:column>` |
| `<div class="flex">` (horizontal) | `<native:row>` |
| Positioned/overlapping layers | `<native:stack>` or `class="absolute top-[N] left-[N]"` |
| `<p>` / `<span>` / headings | `<native:text>` (nest for inline styled runs) |
| `<img>` | `<native:image src alt>` |
| `<button>` / `<a>` styled as button | `<native:button label @press>` (theme-styled) or `<native:pressable>` (full control) |
| `<input type="text">` | `<native:outlined-text-input>` / `<native:filled-text-input>` / `<native:bare-text-input>` |
| `<input type="checkbox">` | `<native:checkbox>` or `<native:toggle>` |
| `<select>` | `<native:select :options>` |
| `<input type="range">` | `<native:slider>` |
| `<ul>` of rows / tables | `<native:list>` + `<native:list-item>` (swipe actions, pull-to-refresh built in) |
| Card grids | `<native:lazy-grid :columns>` |
| Long scrollable page | `<native:scroll-view>` wrapping one column |
| Modal / dialog markup | `<native:modal>` / `<native:bottom-sheet>` (or `Dialog::alert()` for confirmations) |
| `<hr>` | `<native:divider />` |
| Emoji used as icons, icon fonts, inline SVGs | `<native:icon>` — prefer typed enums: `:ios="Ios::Gearshape" :android="Android::Settings"` (via `@use('App\Icons\Ios')` etc., generated by `native-ui:generate-icons`); never emoji |
| Web fonts (`@font-face`, Google Fonts `<link>`) | Font files in `resources/fonts/` + `font="Inter-Bold"` attribute (download with `php artisan native:font Inter`); app-wide default via the `font-family` token in `config/native-ui.php` |
| Nav bar / tab bar / drawer markup | `NavBar` / `TabBar` / `Drawer` builders in a `NativeLayout` |
| Loading spinners | `<native:activity-indicator>`, or `loading` prop on buttons, or `#[Lazy]` on the component |
| Missing native capability (map, editor, SDK) | An existing marketplace plugin, or a custom plugin (`native:plugin:create`) — plugins can ship native EDGE components; `<native:web-view>` only as the very last resort |

## JavaScript Device Calls → PHP

```javascript
// Before (web view)
import { camera, on, Events } from '#nativephp';
await camera.getPhoto();
on(Events.Camera.PhotoTaken, handler);
```

```php
// After (NativeComponent)
Camera::getPhoto();

#[On(PhotoTaken::class)]
public function handlePhoto(string $path): void { /* ... */ }
```

Listeners auto-teardown on unmount — no `off()` bookkeeping.

## Pitfalls

- Only the supported Tailwind subset applies (see the `edge-components/layout` doc) — don't carry over arbitrary
  CSS-dependent classes; verify each class or use `prefix-[value]` arbitrary values in dp.
- Raw HTML tags render nothing natively — every element must be an EDGE component.
- `justify-between/around/evenly` need a bounded height/width on the container.
- Key dynamic lists with `:native:key="$item->id"` so inserts don't re-render every row.
- Buttons/toggles/checkboxes are theme-colored by design — for custom-styled tap targets use
  `<native:pressable>`.
- Keyboard handling: pin composers/inputs in a `<native:bottom-bar>`; never add manual keyboard padding.
- Don't convert URLs to web links — native navigation is a stack (`@navigate`, `$this->navigate()`), and
  `exitToWeb()` is the only bridge back to remaining web screens.
- When a screen depends on a native capability that doesn't exist yet (a map SDK, a rich editor, hardware
  access), check the plugin marketplace (`https://plugins.nativephp.com`) for an existing plugin, and if none
  exists recommend building a custom plugin (`php artisan native:plugin:create`) — don't leave the screen in
  the web view because of one missing capability.
- After installing any plugin, complete the full flow — `composer require` alone does nothing:
  `php artisan vendor:publish --tag=nativephp-plugins-provider` (once), then
  `php artisan native:plugin:register vendor/plugin-name`, verify with `php artisan native:plugin:list`, and
  tell the user to rebuild with `native:run`.
