# Opt-in RTL Support

NativePHP Mobile supports first-class **right-to-left (RTL)** layouts for
Arabic, Hebrew, Persian/Farsi, Urdu, and other RTL languages across the iOS
native UI, the Android native UI, and the embedded WebView.

The feature is **fully backward-compatible**: existing applications remain LTR
unless the developer explicitly enables RTL support.

## How direction is decided

The effective layout direction is:

```
isRTL = rtl_support && device language is RTL
```

| `rtl_support` | Device language | Effective direction |
| ------------- | --------------- | ------------------- |
| `false`       | English         | LTR                 |
| `false`       | Arabic          | LTR                 |
| `true`        | English         | LTR                 |
| `true`        | Arabic          | RTL                 |
| `true`        | Hebrew          | RTL                 |
| `true`        | Persian         | RTL                 |
| `true`        | Urdu            | RTL                 |

> **Enabling `rtl_support` does not force the application into RTL.** It allows
> NativePHP to automatically choose RTL or LTR based on the device language.
> `rtl_support = false` is the default for backward compatibility.

## Configuration

Enable RTL and declare the languages your app supports in
`config/nativephp.php`:

```php
'rtl_support' => true,

'localizations' => [
    'en',
    'ar',
],
```

- `rtl_support` — defaults to `false`. When `true`, the native shell and
  WebView may switch to RTL based on the device locale. When `false`, the app
  always behaves LTR regardless of the device locale.
- `localizations` — the BCP 47 language codes your app supports. On iOS these
  are written into the generated `Info.plist` as `CFBundleLocalizations`:

  ```xml
  <key>CFBundleLocalizations</key>
  <array>
      <string>en</string>
      <string>ar</string>
  </array>
  ```

## Blade usage

### `@nativeHead`

Outputs HTML language metadata for the `<html>` tag:

```blade
<html @nativeHead>
```

Renders to:

```html
<html lang="ar" dir="rtl">
```

or:

```html
<html lang="en" dir="ltr">
```

The language comes from `app()->getLocale()`; the direction is derived from
the shared RTL language list.

### `@rtl`

A server-rendered conditional that evaluates the current Laravel locale:

```blade
@rtl
    {{-- RTL-specific content --}}
@else
    {{-- LTR-specific content --}}
@endrtl
```

`@rtl` is for server-rendered application content. It does **not** replace
native device-locale detection — the native shell always follows the device.

## Runtime behavior

The **Laravel locale** (`app()->getLocale()`) and the **device locale** are two
distinct concepts:

- The Laravel locale drives `@nativeHead`, `@rtl`, and Laravel-rendered content.
- The **native operating system locale** is the authoritative source for the
  actual mobile shell direction: the top bar, side navigation, bottom
  navigation, native gestures, and the WebView document direction.

This matters when Laravel reports `en` but the device language is `ar-SA` —
the native runtime still lays out the shell RTL, and the WebView is set to
`<html lang="en" dir="rtl">`.

## Supported RTL languages

The shared RTL list includes `ar`, `he`, `fa`, `ur` (and others). Locale
variants resolve through their base language:

```
ar-SA -> ar -> RTL
en-SA -> en -> LTR
```

## No native code required

After this feature, enabling RTL is a single config change:

```php
'rtl_support' => true,

'localizations' => [
    'en',
    'ar',
],
```

No application-specific Swift or Kotlin modifications are required. The native
shell mirrors the top bar, side navigation, bottom navigation, and WebView
automatically according to the device language.
