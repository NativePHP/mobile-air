# Native UI Plugin Rewrite — Plan

**Author:** Shane Rosenthal
**Date locked:** 2026-04-18
**Last updated:** 2026-04-20
**Status:** Implementation in progress — Phase 4 partially done

---

## Progress snapshot

- ✅ **Phase 1** — rename `compose-ui` → `native-ui` complete
- ✅ **Phase 2** — theme foundation (config, `Theme` class, bridge, iOS/Android stores) complete
- ✅ **Phase 3** — Button pilot complete (semantic variants, Model 3, theme-token consumption, a11y props)
- 🟡 **Phase 4** — rollout in progress
  - ✅ `outlined-text-input` + `filled-text-input` (with K fix applied)
  - ⏭️ next: `native:model` directive (Decision L, added 2026-04-20)
  - ⏳ remaining stateful: `toggle`, `checkbox`, `slider`, `select`, `radio`/`radio-group`
  - ⏳ remaining display/container: `card`, `list-item`, `chip`, `badge`, `tab`/`tab-row`, `bottom-sheet`, `modal`, `button-group`, `carousel`, `progress-bar`, `activity-indicator`, `icon`, nav primitives
- ⏳ **Phase 5** — a11y audit

**Deferred:**
- Canvas/shapes fix — `NodeStyleModifier` paints rect bg behind all elements regardless of shape (iOS). Requires shape-awareness plumbing in mobile-air core.
- `#Preview` / `@Preview` harnesses for text-input variants (button has them).

---

## Motivation

The current `nativephp/compose-ui` plugin has inconsistencies that will be hard to fix after release:

- **Design philosophy isn't decided.** Some components use native primitives (Android TextInput uses `OutlinedTextField`), some don't (Button is a styled `Text` on both platforms). iOS TextInput reproduces Material aesthetics via hand-rolled chrome instead of using iOS-native idioms.
- **No theming system.** Every color is hand-set per component. Consumer apps have no way to set brand colors once and have them propagate.
- **Stringly-typed props** across the bridge. Prop name drift silently produces empty/default values.
- **iOS stateful components have a value-sync bug** (reads `value` prop only on `onAppear`; ignores future updates). Two-way binding with `@model` is broken after form reset or record switches.
- **Missing accessibility.** No roles, no labels, no contrast validation. Blocks regulated-industry adoption.
- **Plugin naming implies Android-only.** `compose-ui` suggests Jetpack Compose specifically; actual scope is "UI kit rendering natively on both platforms."

This plan rewrites the plugin to address all of these with coherent decisions across ~40 components.

---

## Locked decisions

All items were grilled individually; this section captures the outcome, not the deliberation.

### A — Design philosophy

**Native idiom per platform.** iOS components feel iOS. Android components feel Material3. Same PHP API; different visual results. Users on each platform get something that belongs on their OS.

Consequence: plugin renderers on iOS use SwiftUI primitives (`Button`, `TextField`, `Toggle`, `NavigationStack`, `.sheet`, `.searchable`, etc.). On Android they use Material3 (`Button`, `OutlinedTextField`, `Switch`, `Scaffold` + `TopAppBar`, `ModalBottomSheet`, `SearchBar`).

No "Material on iOS" cosplay. No "iOS on Android" cosplay.

### B — Plugin structure

**Single plugin, renamed `compose-ui` → `native-ui`.** No split into core-ui + material-ui. YAGNI on the split until a concrete alternative UI kit demands it. The rename is because the current name falsely implies Android-only.

- Package: `nativephp/compose-ui` → `nativephp/native-ui`
- PHP namespace: `Nativephp\ComposeUi\*` → `Nativephp\NativeUi\*`
- Android package: `com.nativephp.plugins.compose_ui` → `com.nativephp.plugins.native_ui`
- iOS class prefix: `ComposeUI*` → `NativeUI*`
- Bridge method: `ComposeUI.Theme.Set` → `NativeUI.Theme.Set`
- Directory: `~/Herd/Plugins/nativephp/compose-ui/` → `~/Herd/Plugins/nativephp/native-ui/`

### C — Native primitives everywhere

**Semantic variant vocabulary.** Components with visual variants expose `primary | secondary | destructive | ghost`. Each platform maps to its native equivalent:

| Variant | Android (M3) | iOS (SwiftUI) |
|---|---|---|
| `primary` | `Button` (filled) | `Button` + `.borderedProminent` |
| `secondary` | `FilledTonalButton` or `OutlinedButton` | `Button` + `.bordered` |
| `destructive` | `Button` w/ error color | `Button(role: .destructive)` |
| `ghost` | `TextButton` | `Button` + `.plain` |

**Model 3 customization (theme-only).** No per-instance `bg`, `color`, `borderRadius`, `shadow`, `fontSize`, etc. on variant components. All visual customization comes from theme tokens. Escape hatch: drop to `<native:pressable>` with arbitrary content for full visual control.

What *is* allowed per-instance:
- `variant` (the semantic variant)
- `size` (sm/md/lg, maps to native size conventions)
- `disabled`
- `loading` (where applicable)
- `icon` / `icon-trailing` (slot content, not styling)
- `a11y-label`, `a11y-hint`
- `@tap`, `@change`, etc.
- Layout props that apply to every element (padding via spacing scale, width/fill, etc.)

### D — Theme layer

**Minimalist semantic token set.** 17 colors, 4 radii, 4 font sizes, 1 font family.

Token shape:
```php
[
    'light' => [
        'primary' => '#0F766E',             'on-primary' => '#FFFFFF',
        'secondary' => '#64748B',           'on-secondary' => '#FFFFFF',
        'surface' => '#FFFFFF',             'on-surface' => '#0F172A',
        'background' => '#F8FAFC',          'on-background' => '#0F172A',
        'surface-variant' => '#F1F5F9',     'on-surface-variant' => '#475569',
        'outline' => '#CBD5E1',
        'destructive' => '#DC2626',         'on-destructive' => '#FFFFFF',
        'accent' => '#FB923C',              'on-accent' => '#FFFFFF',
    ],
    'dark' => [ /* parallel set */ ],
    'radius-sm' => 4,  'radius-md' => 8,  'radius-lg' => 16,  'radius-full' => 9999,
    'font-sm' => 14,   'font-md' => 16,   'font-lg' => 20,    'font-xl' => 24,
    'font-family' => 'System',
]
```

Note on naming: `destructive`/`on-destructive` replaced the initial draft's `error`/`on-error` to match the semantic variant vocabulary (C). `surface-variant`, `on-surface-variant`, `outline` were added during text-input implementation — they're standard M3 color roles needed for filled fields, muted labels, and neutral borders (dividers, cards, text-field outlines).

**Plugin-local.** Config file + static storage class live inside the native-ui plugin, not in mobile-air core.

- Config: `~/Herd/Plugins/nativephp/native-ui/config/native-ui.php`
- Storage: `Nativephp\NativeUi\Theme` (static class)
- Service provider: `NativeUiServiceProvider` wires `mergeConfigFrom()` + `publishes()`

**Override path:** `Theme::merge([...])` from anywhere (typically the consumer's `NativeAppServiceProvider::boot()`). Static case = edit config. Dynamic case = call merge.

**Transport:** Existing `nativephp_call('NativeUI.Theme.Set', json_encode(Theme::all()))` bridge. Sent once at boot, re-sent on any `Theme::merge()`. Native stores in memory across renders. Zero mobile-air changes.

**Semantics:**
- Deep merge on repeated `Theme::merge()` calls
- Auto-derive dark mode when only `light` is provided (invert luminance on colors, keep radii/spacing/fonts)
- No nested `<native:theme>` overrides (hard defer — not v1 or v2)

### E — Slots over string props

**Text-accepting components take their content from the Blade slot, not a `label` string.**

Applies to: `button`, `chip`, `tab`, `list-item` (headline), `badge`.

```blade
{{-- Was: --}}
<native:button label="Save" variant="primary" @tap="save" />

{{-- Now: --}}
<native:button variant="primary" @tap="save">Save</native:button>

{{-- Icon + text: --}}
<native:button variant="primary" @tap="add">
    <native:icon name="plus" />
    Add item
</native:button>
```

HTML/Blade-like feel. Buttons can contain composed content without adding `label_icon`, `label_weight`, `label_color` props.

### F — Typed prop DTOs (SKIPPED)

**Not doing.** Stringly-typed props remain. Rationale: cost (1–2 weeks + forever-maintenance) exceeds benefit (catches a class of quiet bugs that manual testing also catches). Revisit only if prop-drift bugs become a regular tax.

### G — Per-variant elements

**Split variant-switch renderers into separate elements.** Where a single element currently branches on `variant=N` with largely duplicate code per branch, each variant becomes its own element with shared logic extracted.

Concrete: `text_input` becomes `outlined-text-input` + `filled-text-input` (both map to existing Material3 primitives). `button` still exposes `variant` via the semantic vocabulary (not a split) because its variants are true peers, not different underlying primitives.

Rule of thumb: if the platforms' native primitives are *different classes* per variant (e.g., `OutlinedTextField` vs `TextField`), split. If the platforms use *one class with different modifiers/styles* per variant (e.g., `Button` + `.borderedProminent` vs `Button` + `.bordered`), keep as single element with `variant` prop.

### H — Shared utilities

One helper file per platform inside the plugin's `resources/` tree:

- `~/Herd/Plugins/nativephp/native-ui/resources/android/NativeUIHelpers.kt`
- `~/Herd/Plugins/nativephp/native-ui/resources/ios/NativeUIHelpers.swift`

Extract duplicated helpers: `resolveFontWeight`, `resolveKeyboardType`, ARGB conversion utilities, icon-name → system-symbol mapping, theme-token accessors.

### I — Preview harnesses

Every renderer ships with a platform-native preview block:

- Kotlin: `@Preview` composable rendering the component with a sample `NativeUINode`
- Swift: `#Preview { ... }` macro same

Drops iteration time from "rebuild PHP + rebuild app + navigate to screen" to "open file, hit preview."

### J — Accessibility

**Scope: enhanced.** Every interactive component declares its native role (button, switch, checkbox, slider, tab, radio). Disabled state exposed. Dynamic type supported. Swipe actions discoverable. Roles mapped:

| Component | iOS | Android |
|---|---|---|
| button | `.accessibilityAddTraits(.isButton)` | `Role.Button` |
| toggle | `.accessibilityValue(...)` | `Role.Switch` |
| checkbox | `.accessibilityAddTraits(.isToggle)` | `Role.Checkbox` |
| slider | `.accessibilityValue(...)` + min/max | `Role.Slider` |
| tab | `.accessibilityAddTraits(.isTabButton)` | `Role.Tab` |
| radio | `.accessibilityValue(...)` | `Role.RadioButton` |

**Label source:** implicit from content/slot by default; `a11y-label` attribute overrides. Dev-mode warning when an interactive component has no slot content and no explicit label. `a11y-hint` available for supplementary context.

**Color contrast:** dev-mode warning when primary/on-primary ratio < 4.5:1 (WCAG AA). Does NOT block builds.

**Not in scope for v1:** formal WCAG 2.2 AA compliance audit, accessibility statement, automated contrast validation across entire token set. These are v2+ goals.

### K — Value sync for stateful components

**Echo-prevention pattern applied to all 6 stateful components:** `text_input`, `toggle`, `checkbox`, `slider`, `select`, `radio_group`.

Current bug: iOS TextInput reads `value` prop only on `.onAppear`; never re-syncs after mount. Android works via `remember(node.id, initialValue)` which re-keys on change.

Fix pattern: track `lastSentValue` alongside local state. When incoming `value` prop differs from *both* local and last-sent, sync. When it matches last-sent, it's an echo from our own change event — ignore.

Supports `@model` two-way binding after form reset, record switches, programmatic updates.

**Implementation notes (discovered during Phase 4 text-input):**

- iOS: `@State text`, `@State lastSentValue`, `@State initialized`. `onAppear` seeds from server value once. `onChange(of: serverValue)` syncs only when `new != lastSentValue`. `onChange(of: text)` updates `lastSentValue` before dispatching the change event.
- Android: `remember { mutableStateOf(serverValue) }` for both `text` and `lastSentValue`. `LaunchedEffect(serverValue) { if (serverValue != lastSentValue) { text = serverValue; lastSentValue = serverValue } }`.
- **Theme-store observer churn gotcha.** `@Published` (iOS) and `mutableStateOf` (Android) notify on every assignment, even for value-equal writes. Since PHP's service provider boot re-pushes the theme on every request, unguarded theme assignments forced every observer (Screen, every Button, every TextInput) to re-render on every Livewire event. Fix: `apply()` only assigns when the new tokens actually differ (`NativeUITokens` is `Equatable`). Applied to both platforms.

### L — Value-sync ergonomics (`native:model`)

**Livewire-parity directive on native elements.** Takes the `:value="$foo"` + `@change="..."` pair that every stateful component needs and wraps it in a single directive matching the shape Laravel devs already know:

```blade
<native:outlined-text-input native:model="name"/>              {{-- default: live + echo-prevention --}}
<native:outlined-text-input native:model.live="name"/>         {{-- explicit keystroke --}}
<native:outlined-text-input native:model.blur="name"/>         {{-- commit on focus loss --}}
<native:outlined-text-input native:model.debounce.300ms="name"/> {{-- debounced live --}}
<native:outlined-text-input native:model.lazy="name"/>         {{-- alias for blur --}}
```

**Why `native:` not `wire:`?** Native-rendered screens aren't DOM — `wire:model` targets DOM inputs via JS. Native elements are rendered by SwiftUI/Compose from a shared-memory tree. They need their own model directive with the same vocabulary but a different implementation path. `wire:model` continues to work on WebView/Jump screens; `native:model` is the equivalent in the native context. Users don't mix them on a single element.

**What happens under the hood:**

1. Blade tag precompiler sees `native:model="foo"` with modifiers → emits `:value="$foo" @change="$set('foo', $value)"` plus a `sync_mode` prop indicating live / blur / debounce.
2. `BaseTextInput` (and future `BaseToggle`, `BaseSlider`, etc.) carries `sync_mode` + optional `debounce_ms` into its element props.
3. iOS `NativeUITextInputCore` + Android `parseTextInputProps` branch on `sync_mode`:
   - `live` (default): existing behavior — dispatch onChange on each keystroke, echo-prevention active.
   - `debounce`: accumulate changes locally, dispatch onChange after `debounce_ms` of inactivity.
   - `blur`: dispatch only on focus loss / submit.

**Default is `live`.** Matches current text-input behavior and `wire:model.live` parity. Echo-prevention stays on in all modes.

**Scope:** land in the session immediately after text-input pilot (i.e. next), before building the remaining stateful components. Those components inherit the plumbing and wire in directly — retrofitting is cheap because the Core helper owns the state machine.

---

## Rollout phases

Total estimate: ~4 weeks of focused work.

### Phase 1 — Rename `compose-ui` → `native-ui` (~1 day)

Mechanical. No behavior change. Validates build on both platforms before proceeding.

**File inventory (plugin-internal):**

- `composer.json` — package name
- `nativephp.json` — namespace field, ~40 component entries with updated class paths
- `src/ComposeUIServiceProvider.php` → `src/NativeUIServiceProvider.php`
- `src/Components/*.php` — namespace declarations
- `src/Elements/*.php` — namespace declarations
- `resources/android/*.kt` — package declarations + imports
- `resources/ios/*.swift` — class name prefixes
- Directory rename on disk

**File inventory (external references to update):**

- `~/Herd/mobile-air/composer.json` — any dev require
- `~/Herd/mobile-air/composer.lock` — after composer update
- Consumer apps' `composer.json` — document the rename in changelog
- Any Blade templates referencing `<x-compose-ui::*>` style names — none expected since native-tag precompiler handles `<native:*>` directly

**Native bridge changes:**

- Android: bridge method name in dispatch table
- iOS: bridge method name in dispatch table
- Update `Theme.Set` → `NativeUI.Theme.Set` on both sides (though the theme class doesn't exist yet — Phase 2 creates it)

**Success criteria:**

- `composer dump-autoload` passes in mobile-air
- Android build passes
- iOS build passes
- All existing components render identically (no visual regression)

### Phase 2 — Theme foundation (~2–3 days)

Introduce the theme layer. Nothing consumes it yet — components still hardcode colors.

**New files:**
- `~/Herd/Plugins/nativephp/native-ui/config/native-ui.php` — default theme tokens
- `~/Herd/Plugins/nativephp/native-ui/src/Theme.php` — static storage class
- `~/Herd/Plugins/nativephp/native-ui/resources/android/NativeUITheme.kt` — theme store + MaterialTheme mapping
- `~/Herd/Plugins/nativephp/native-ui/resources/ios/NativeUITheme.swift` — theme store + SwiftUI environment value

**Modified files:**
- `src/NativeUIServiceProvider.php` — wire `mergeConfigFrom()`, `publishes()`, load Theme, push to native on boot
- Android/iOS bridge dispatch tables — register `NativeUI.Theme.Set` handler

**Success criteria:**
- `php artisan vendor:publish --tag=native-ui-config` drops config file
- `Theme::merge([...])` deep-merges and pushes to native
- `Theme::all()` returns expected effective tokens
- Dark mode auto-derivation produces readable results when only `light` set
- Native side receives and stores theme; logging confirms

### Phase 3 — Pilot: Button (~2–3 days)

Button is the reference implementation. Validates the new API end-to-end before rolling out.

**Scope:**
- Rewrite `src/Elements/Button.php` to use new API (variant, size, slot content, a11y)
- Rewrite `src/Components/Button.php` Blade component
- Rewrite `resources/android/ButtonRenderer.kt` to use M3 `Button` family
- Rewrite `resources/ios/NativeUIButtonRenderer.swift` to use SwiftUI `Button` with styles
- Extract shared helpers to `NativeUIHelpers.{kt,swift}` as duplication emerges
- Add `@Preview` / `#Preview` for each variant
- Add a11y role, label handling, disabled state

**Success criteria:**
- `<native:button variant="primary">Save</native:button>` renders natively on both platforms
- All four variants (primary, secondary, destructive, ghost) render correctly
- Theme changes propagate without component changes
- VoiceOver (iOS) announces "Save, button"
- TalkBack (Android) announces "Save button"
- Preview harnesses render in Xcode + Android Studio

### Phase 4 — Pattern rollout (~12–17 focused sessions)

Apply Button's pattern to remaining components. Parallelizable per-component.

**Priority order within Phase 4** (high-use first, with L inserted after the text-input pilot):

1. ✅ `text-input` (split into `outlined-text-input` + `filled-text-input`, K fix applied)
2. ⏭️ **L — `native:model` directive + sync-mode plumbing** (next)
3. `toggle` — K fix + L
4. `checkbox` — K fix + L
5. `slider` — K fix + L
6. `select` — K fix + L
7. `radio` + `radio-group` — K fix + L
8. `card`
9. `list-item`
10. `chip`
11. `badge`
12. `tab` + `tab-row`
13. `bottom-sheet`
14. `modal`
15. `button-group`
16. `carousel`
17. `progress-bar`
18. `activity-indicator`
19. `icon`
20. Nav primitives (`top-bar`, `bottom-nav`, `side-nav`) — rewrite to use `NavigationStack` / `Scaffold`+`TopAppBar`+`NavigationBar`

**Per-component checklist:**
- [ ] Element class updated (slot support where applicable, variant prop with semantic values)
- [ ] Blade component updated
- [ ] Android renderer uses M3 primitive
- [ ] iOS renderer uses SwiftUI primitive
- [ ] Shared helpers used, no duplication
- [ ] Theme tokens consumed; no hardcoded colors
- [ ] A11y role + label handling
- [ ] K echo-prevention pattern (stateful components only)
- [ ] L `native:model` modifiers honored (stateful components only)
- [ ] `@Preview` / `#Preview` for each variant

**Sizing** (sessions = 2–4 focused hours each):
- L directive + sync-mode wiring: ~1 session
- Stateful inputs (toggle/checkbox/slider/select/radio): ~4–6 sessions total
- Display/container (13 components): ~5–8 sessions total — most exist from rename phase, work is Model 3 enforcement + theme refactor + a11y
- Phase 5 a11y audit + preview harnesses + canvas/shapes fix: ~2 sessions

### Phase 5 — A11y audit + polish (~1 week)

Systematic pass over all rewritten components.

- VoiceOver walk-through on a reference app
- TalkBack walk-through on a reference app
- Dynamic type scaling test
- Reduce-motion respect
- Contrast validation with default theme
- Fix dynamic-type layout fallout

---

## Working discipline

**Branches:**
- `~/Herd/mobile-air` — branch off `element`
- `~/Herd/Plugins/nativephp/compose-ui` — branch off `main`
- Branch name (both): `native-ui-rewrite`

**Commits:** no git commands run without explicit user instruction. User manages branch creation, commits, pushes, merges.

**Binaries:** no PHP rebuild needed for Phase 1 (pure Laravel/Kotlin/Swift). Phase 2 might introduce bridge methods; rebuild required if new C-side functions added (not expected — using existing `nativephp_call`).

**Native file sync:** per `feedback_no_sync` memory — never copy files between the plugin and `~/Herd/native/nativephp/`. User's install/run scripts handle placement.

---

## Out of scope (not this plan)

- Flat-buffer struct shrink (parked; revisit post-release if needed)
- Stable semantic IDs for nodes (reverted)
- Persistent native tree with object reuse
- Patches-instead-of-tree-publish rendering model
- Post-release bridge-version handshake (`project_bridge_version_check` memory)
- Cupertino-UI / alternative design system plugins
- Nested `<native:theme>` overrides
- Typed prop DTOs via codegen
- Formal WCAG compliance audit

---

## Appendix: grilling transcript reference

Decisions were reached through structured `/grill-me` interview covering each recommendation. Key branches explored:

- Philosophy (native-per-platform vs material-everywhere vs author-choice)
- Variant vocabulary (semantic vs platform-native names)
- Customization model (unlimited vs guardrails vs theme-only)
- Theme scope (minimalist vs M3-faithful vs two-tier)
- Theme declaration (config vs facade vs Blade vs combination)
- Plugin split (new core-ui vs rename only vs primitives to mobile-air)
- Typed DTO cost/benefit (full codegen vs runtime validation vs skip)
- A11y scope (core vs enhanced vs full WCAG)
- Label sourcing (implicit vs explicit vs hybrid)
- Value-sync policy (hard overwrite vs echo prevention vs clean/dirty)

Rationale for each final decision is captured inline above.
