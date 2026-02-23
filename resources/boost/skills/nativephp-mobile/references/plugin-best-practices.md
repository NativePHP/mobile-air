# NativePHP Plugin Best Practices

Guidelines for building and publishing NativePHP v3 plugins.

## What Plugins Are

Plugins extend NativePHP for Mobile with native functionality. They package PHP code, native implementations (Swift/iOS and Kotlin/Android), and a manifest declaring requirements and capabilities.

## Plugin Capabilities

Plugins can leverage:

- **Bridge functions** — call native code from PHP with results returned
- **Events** — dispatch from native code to Laravel/Livewire
- **Permissions** — declare required permissions (camera, location, etc.)
- **Dependencies** — Gradle, CocoaPods, or Swift Package Manager
- **Custom repositories** — for enterprise/private SDKs
- **Android components** — Activities, Services, Receivers, Content Providers
- **Asset bundling** — ML models, configuration files
- **Lifecycle hooks** — build-time commands
- **Secrets** — environment variables with validation
- **init_function** — native code called during app initialization

## Directory Structure

```
my-plugin/
├── composer.json                  # type must be "nativephp-plugin"
├── nativephp.json                 # central manifest
├── src/                           # PHP classes, facades, events, service provider
├── resources/
│   ├── android/src/               # Kotlin bridge functions
│   ├── ios/Sources/               # Swift bridge functions
│   └── js/                        # JavaScript library stubs
```

Scaffold a plugin with: `php artisan native:plugin:create`

## Manifest (nativephp.json)

The manifest is the central configuration file. Key fields:

| Field | Purpose |
|---|---|
| `namespace` | Plugin identifier for code generation |
| `bridge_functions` | Maps PHP calls to native implementations |
| `events` | Event classes the plugin dispatches |
| `init_function` | Native function called during app init |
| `android.permissions` | Required Android permissions |
| `android.dependencies` | Gradle dependencies |
| `android.meta_data` | Android metadata entries |
| `ios.info_plist` | Info.plist entries for permissions/APIs |
| `ios.dependencies` | CocoaPods/SPM dependencies |
| `ios.entitlements` | iOS entitlements |
| `ios.capabilities` | iOS capabilities |
| `hooks` | Lifecycle commands at specific build stages |
| `secrets` | Required environment variables |

Bridge function declaration example:

```json
{
    "name": "MyPlugin.DoSomething",
    "ios": "MyPluginFunctions.DoSomething",
    "android": "com.myvendor.plugins.myplugin.MyPluginFunctions.DoSomething"
}
```

## Android Requirements

Kotlin files **must** declare vendor-namespaced packages to prevent conflicts:

```kotlin
package com.myvendor.plugins.myplugin
```

The compiler places files based on their package declaration — always include it.

## JavaScript Integration

Provide a JS library that wraps `bridgeCall()` for every bridge function:

```javascript
async function bridgeCall(method, params = {}) {
    const response = await fetch('/_native/api/call', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ method, params })
    });
    return response.json();
}
```

Consider publishing as an npm package with TypeScript definitions.

## Typical Usage Pattern

```php
use Vendor\MyPlugin\Facades\MyPlugin;

MyPlugin::doSomething(); // Call native functions

#[OnNative(MyPlugin\Events\SomethingHappened::class)]
public function handleResult($data) { } // Listen for events
```

## Requirements

- **JavaScript library**: Export JS functions for each bridge method. Must work across Livewire v3/v4, Inertia + Vue, and Inertia + React. Document any stack limitations.
- **Real device testing**: Test on physical Android and iOS devices — emulators lack camera, biometrics, and hardware features. Provide TestFlight / Google Play test builds.
- **Documentation**: README must include installation steps, PHP and JS usage examples, method/event/permission docs, and environment variable configuration.

## Development Workflow

**Local development** — add as path repository in the app's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../packages/my-plugin" }
    ]
}
```

- PHP changes take effect immediately
- Native code changes require: `php artisan native:run`
- Significant manifest changes may need: `php artisan native:install --force`

**Registration** — after composer install, explicitly register the plugin:

```
php artisan native:plugin:register vendor/plugin-name
```

This adds the service provider to `NativeServiceProvider.php` and acts as a security gate against unauthorized transitive dependencies.

## Commands

- `php artisan native:plugin:create` — Scaffold a new plugin interactively
- `php artisan native:plugin:register vendor/name` — Register plugin in the app
- `php artisan native:plugin:validate` — Catch manifest errors, bridge function mismatches, and missing assets before release
- `php artisan native:plugin:boost` — Generate AI-friendly guidelines at `resources/boost/guidelines/core.blade.php`
- `php artisan native:plugin:install-agent` — Install specialized AI agents for Kotlin, Swift, and JavaScript development

## Submission Checklist

- README documents installation, PHP, and JavaScript usage with examples
- All public methods, events, and permissions documented
- JavaScript exports for every bridge function
- Android Kotlin files use vendor-namespaced packages
- Manifest validated with zero errors (`native:plugin:validate`)
- Tested on physical Android and iOS devices
- Works with Livewire v3/v4, Inertia + Vue, Inertia + React
- Boost guidelines included (`native:plugin:boost`)
- Test build links provided (TestFlight / Google Play)
- Secrets and environment variables documented
- Changelog maintained
