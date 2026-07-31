---
name: nativephp-mobile
description: "Builds native iOS and Android apps with PHP & Larvel. Activate when using native device APIs (camera, dialog, biometrics, scanner, geolocation, push notifications), EDGE components (bottom-nav, top-bar, side-nav), `#nativephp` JavaScript imports, native mobile events, NativePHP Artisan commands (native:run, native:install, native:watch), deep links, secure storage, or mobile app deployment."
---

# NativePHP Mobile v3

## Documentation

Before implementing any feature, fetch the relevant docs using `WebFetch`. Find the right URL in [references/available-docs.md](references/available-docs.md).

```
WebFetch("https://nativephp.com/docs/mobile/3/apis/camera", "Explain Camera API methods, events, and fluent builder options")
```

## Build Commands — Tell the User, Don't Run

Never auto-run these commands. Always tell the user to run them manually:

```bash
npm run build -- --mode=ios      # or --mode=android
php artisan native:run ios       # or android
php artisan native:watch
./native open
./native watch
```

## Environment Detection

Before suggesting commands, determine:

1. **OS**: macOS supports iOS + Android. Windows/Linux support Android only. WSL unsupported.
2. **Frontend stack**: Livewire/Blade (`.blade.php` with `wire:`, `app/Livewire/`) vs JavaScript (`.vue`/`.jsx`/`.tsx`, `inertiajs` in `package.json`).

## Required Environment Variables

Set in `.env` before `php artisan native:install`:

```dotenv
NATIVEPHP_APP_ID=com.yourcompany.yourapp
NATIVEPHP_APP_VERSION="DEBUG"
NATIVEPHP_APP_VERSION_CODE="1"
# Optional for iOS:
NATIVEPHP_DEVELOPMENT_TEAM=XXXXXXXXXX
```

## Artisan Commands Reference

| Command | Purpose |
|---------|---------|
| `php artisan native:install` | Install/upgrade native shell |
| `php artisan native:run ios/android` | Build and launch on simulator/emulator |
| `php artisan native:watch` | Hot reload during development |
| `php artisan native:jump` | Quick restart without full rebuild |
| `php artisan native:tail` | Stream device logs |
| `php artisan native:package` | Package for App Store / Play Store |

HMR with Vite works when `npm run dev` runs alongside `native:run`.

## Vite Configuration

```javascript
import { nativephpMobile, nativephpHotFile } from './vendor/nativephp/mobile/resources/js/vite-plugin.js';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            hotFile: nativephpHotFile(),
        }),
        tailwindcss(),
        nativephpMobile(),
    ]
});
```

## JavaScript Usage (Vue/React/Inertia)

```javascript
import { camera, dialog, scanner, biometric, on, off, Events } from '#nativephp';

await camera.getPhoto();
await dialog.alert('Title', 'Message');
await scanner.scan().prompt('Scan ticket').formats(['qr', 'ean13']);
await biometric.prompt().id('auth-check');
```

Event handling — always clean up in `onUnmounted`:

```javascript
const handler = (payload) => { /* handle */ };
on(Events.Camera.PhotoTaken, handler);

// onUnmounted:
off(Events.Camera.PhotoTaken, handler);
```

## PHP Usage (Livewire/Blade)

Core Facades (`Native\Mobile\Facades`): `Camera`, `Dialog`, `Biometrics`, `Network`, `SecureStorage`, `File`, `Share`, `Haptics`, `System`, `Device`

Plugin Facades (separate packages): `Browser`, `Scanner`, `Microphone`, `Geolocation`, `PushNotifications`

```php
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\Camera\PhotoTaken;

#[OnNative(PhotoTaken::class)]
public function handlePhoto(string $path): void
{
    // Process photo at $path
}
```

## Event Handling

- **Sync** returns directly: `SecureStorage::get()`, `Network::status()`
- **Async** dispatches events: `Camera::getPhoto()` dispatches `PhotoTaken`
- Custom events: `->event(CustomEvent::class)` (PHP) or `.event('App\\Events\\Custom')` (JS)
- Events dispatch to both JS and PHP simultaneously

## EDGE Components (Native UI)

EDGE renders Blade components as native UI. Works with both Livewire and Inertia (layout is Blade).

- Prefix: `native:bottom-nav`, `native:top-bar`, `native:side-nav`
- Child items require unique `id` attributes
- Add `nativephp-safe-area` class to body for notch handling

```blade
<native:bottom-nav>
    <native:bottom-nav-item id="home" icon="home" label="Home" :url="route('home')" />
    <native:bottom-nav-item id="profile" icon="person" label="Profile" :url="route('profile')" />
</native:bottom-nav>
```

## Plugin System (v3)

Modular plugin architecture — device features as separate Composer packages:

| Package | Feature | Cost |
|---------|---------|------|
| `nativephp/mobile-browser` | In-app browser, OAuth | Free |
| `nativephp/mobile-camera` | Camera & photo picker | Free |
| `nativephp/mobile-dialog` | Alerts & toasts | Free |
| `nativephp/mobile-device` | Vibrate, flashlight, device info | Free |
| `nativephp/mobile-file` | File move/copy | Free |
| `nativephp/mobile-microphone` | Audio recording | Free |
| `nativephp/mobile-network` | Network status | Free |
| `nativephp/mobile-share` | Share URLs & files | Free |
| `nativephp/mobile-system` | Open app settings | Free |
| `nativephp/mobile-scanner` | QR/barcode scanning | $49 |
| `nativephp/mobile-biometrics` | Face ID / Touch ID | $49 |
| `nativephp/mobile-geolocation` | GPS location | $49 |
| `nativephp/mobile-secure-storage` | Keychain/Keystore | $49 |
| `nativephp/mobile-firebase` | Push notifications | Proprietary |

For authoring plugins: [references/plugin-best-practices.md](references/plugin-best-practices.md)

## Common Pitfalls

- Missing `NATIVEPHP_APP_ID` in `.env` before `native:install`
- Suggesting iOS commands on Windows/Linux
- Not cleaning up event listeners with `off()` in `onUnmounted`
- Missing unique `id` on EDGE component children
- Forgetting `nativephp-safe-area` class on body
- Using core facades for plugin features (e.g. `Scanner` requires `nativephp/mobile-scanner`)
- Forgetting `nativephpMobile()` / `nativephpHotFile()` in `vite.config.js`
- Not passing `--mode=ios` or `--mode=android` to `npm run build`
- Not fetching v3 docs before implementing — use WebFetch with URLs from [references/available-docs.md](references/available-docs.md)
