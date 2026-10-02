# NativePHP God Method Bridge - Complete Implementation Guide

A protocol-based routing system for calling native iOS functions from PHP. This is the **god method** - a unified bridge that allows calling any registered native function through `nativephp_call('Method.Name', params)`.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Quick Start](#quick-start)
- [Creating New Functions](#creating-new-functions)
- [PHP Integration](#php-integration)
- [JavaScript Integration](#javascript-integration)
- [Event System](#event-system)
- [Best Practices](#best-practices)
- [Examples](#examples)

---

## Overview

### What is the God Method?

The god method is `nativephp_call('SOME.STRING', '{params: true}')` - it routes to native iOS/Android functions without requiring new PHP functions to be compiled into the binaries.

**Key Features:**
- Single entry point for all native calls
- Dynamic function registration
- Type-safe parameter handling
- Consistent error handling
- Event-driven async operations
- Third-party plugin support

### How It Works

```
PHP: nativephp_call('Device.GetInfo', '{}')
  ↓
Bridge Router: NativePHPCall()
  ↓
Registry: Find "Device.GetInfo"
  ↓
Execute: DeviceFunctions.GetInfo()
  ↓
Return: JSON response
```

---

## Architecture

### Core Components

```
BridgeFunctionRegistry (Singleton)
├── Registered Functions Map
│   ├── "Device.GetInfo" → DeviceFunctions.GetInfo()
│   ├── "Camera.GetPhoto" → CameraFunctions.GetPhoto()
│   └── "Dialog.Alert" → DialogFunctions.Alert()
│
BridgeRouter
├── NativePHPCan(method) → Check if method exists
└── NativePHPCall(method, params) → Execute method

BridgeFunction Protocol
└── execute(parameters: [String: Any]) throws -> [String: Any]

LaravelBridge.shared
└── send?(eventClass, payload) → Dispatch events to PHP/Livewire
```

### Response Format

**Success:**
```json
{
  "status": "success",
  "data": {
    "key": "value"
  }
}
```

**Error:**
```json
{
  "status": "error",
  "code": "ERROR_CODE",
  "message": "Human-readable message",
  "data": {}
}
```

### Error Codes

- `FUNCTION_NOT_FOUND` - Method doesn't exist in registry
- `INVALID_PARAMETERS` - Missing or malformed parameters
- `INVALID_JSON` - JSON parsing failed
- `EXECUTION_FAILED` - Function execution error
- `PERMISSION_DENIED` - Permission denied by user
- `PERMISSION_REQUIRED` - Permission needs requesting
- `UNKNOWN_ERROR` - Unexpected error
- `SERIALIZATION_ERROR` - Response serialization failed

---

## Quick Start

### 1. Check Available Functions

```php
// Check if method exists before calling
if (nativephp_can('Device.GetInfo')) {
    $result = nativephp_call('Device.GetInfo', '{}');
}
```

### 2. Call a Function

```php
$result = nativephp_call('Device.Vibrate', '{}');
$decoded = json_decode($result, true);

if ($decoded['status'] === 'success') {
    // Success!
} else {
    // Handle error: $decoded['message']
}
```

### 3. Listen for Events

```php
// In your Livewire component
#[On('native:Native\Mobile\Events\Camera\PhotoTaken')]
public function handlePhotoTaken($path, $mimeType = null)
{
    $this->photoPath = $path;
}
```

---

## Creating New Functions

### Step 1: Create Function Class

Create in `Bridge/Functions/YourFunctions.swift`:

```swift
import Foundation
import UIKit

enum YourFunctions {

    // MARK: - Your.Method

    /// Description of what this method does
    /// Parameters:
    ///   - param1: (required) string - Description
    ///   - param2: (optional) int - Description (default: 10)
    ///
    /// Usage Example:
    ///   nativephp_call('Your.Method', '{"param1": "value", "param2": 20}')
    class Method: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // 1. Extract and validate parameters
            guard let param1 = parameters["param1"] as? String else {
                throw BridgeError.invalidParameters("Missing 'param1' parameter")
            }

            let param2 = parameters["param2"] as? Int ?? 10

            // 2. Perform operation
            let result = doSomething(param1: param1, param2: param2)

            // 3. Return data
            return [
                "result": result,
                "success": true
            ]
        }

        private func doSomething(param1: String, param2: Int) -> String {
            // Your implementation
            return "Done"
        }
    }
}
```

### Step 2: Register Function

Add to `BridgeFunctionRegistration.swift`:

```swift
func registerBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    // ... existing registrations ...

    registry.register("Your.Method", function: YourFunctions.Method())
}
```

### Step 3: Create PHP Facade

Create `src/Your.php`:

```php
<?php

namespace Native\Mobile;

class Your
{
    public function method(string $param1, int $param2 = 10): ?string
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Your.Method', json_encode([
                'param1' => $param1,
                'param2' => $param2
            ]));

            if ($result) {
                $decoded = json_decode($result, true);
                return $decoded['result'] ?? null;
            }
        }

        return null;
    }
}
```

### Step 4: Update JavaScript Library

Add to `resources/dist/native.js`:

```javascript
/**
 * Call your method
 * @param {string} param1 - Description
 * @param {number} param2 - Description (default: 10)
 * @returns {Promise<Object>}
 */
export async function method(param1, param2 = 10) {
    return bridgeCall('Your.Method', { param1, param2 });
}

export const your = {
    method: method
};
```

**Done!** Users can now call:
- **PHP**: `Your::method('value')`
- **JavaScript**: `your.method('value')`
- **Direct**: `nativephp_call('Your.Method', '{}')`

---

## PHP Integration

### Method Naming Convention

**Bridge Method** → **PHP Facade Method**

```
Device.ToggleFlashlight  →  Device::flashlight()
Device.GetId            →  Device::getId()
Camera.GetPhoto         →  Camera::getPhoto()
```

The PHP facade uses clean method names while the bridge uses descriptive names (GetPhoto, ToggleFlashlight, etc.).

### Facade Pattern

```php
<?php

namespace Native\Mobile;

class Device
{
    public function flashlight(): array
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Device.ToggleFlashlight', '{}');
            if ($result) {
                $decoded = json_decode($result, true);
                return [
                    'success' => $decoded['success'] ?? false,
                    'state' => $decoded['state'] ?? false,
                ];
            }
        }

        return [
            'success' => false,
            'state' => false,
        ];
    }
}
```

### Async Operations with Events

For async operations, return acknowledgment immediately and listen for events:

```php
// Trigger async operation
nativephp_call('Camera.GetPhoto', '{"id": "profile-photo"}');

// Listen in Livewire component
#[On('native:Native\Mobile\Events\Camera\PhotoTaken')]
public function handlePhotoTaken($path, $mimeType = null, $id = null)
{
    if ($id === 'profile-photo') {
        $this->updateProfilePhoto($path);
    }
}
```

---

## JavaScript Integration

### Method Naming Convention

JavaScript matches PHP facade naming:

```javascript
// JavaScript
device.flashlight()
device.getId()
camera.getPhoto()

// Calls same bridge methods as PHP
bridgeCall('Device.ToggleFlashlight', {})
bridgeCall('Device.GetId', {})
bridgeCall('Camera.GetPhoto', {})
```

### Event Constants

Use `NativeEvents` for type-safe event listening:

```javascript
import { on, NativeEvents } from '@nativephp/native';

// Clean, autocomplete-friendly syntax
onMounted(() => {
    on(NativeEvents.Camera.PhotoTaken, handlePhoto);
    on(NativeEvents.Alert.ButtonPressed, handleButton);
});

// Instead of error-prone strings:
// on('Native\\Mobile\\Events\\Camera\\PhotoTaken', handlePhoto);
```

### Available Event Constants

```javascript
NativeEvents.Alert.ButtonPressed
NativeEvents.App.UpdateInstalled
NativeEvents.Biometric.Completed
NativeEvents.Camera.PhotoTaken
NativeEvents.Camera.PhotoCancelled
NativeEvents.Camera.VideoRecorded
NativeEvents.Camera.VideoCancelled
NativeEvents.Gallery.MediaSelected
NativeEvents.Geolocation.LocationReceived
NativeEvents.Geolocation.PermissionStatusReceived
NativeEvents.Geolocation.PermissionRequestResult
NativeEvents.Microphone.MicrophoneRecorded
NativeEvents.Microphone.MicrophoneCancelled
NativeEvents.Scanner.CodeScanned
```

---

## Event System

### How Events Work

```
Swift → LaravelBridge.shared.send?(eventClass, payload)
  ↓
JavaScript injection: window.Livewire.dispatch('native:EventClass', payload)
  ↓
Livewire component: #[On('native:EventClass')]
```

### Creating Events

**1. Create PHP Event Class:**

```php
<?php

namespace Native\Mobile\Events\Your;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ThingHappened
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $result,
        public ?string $id = null
    ) {}
}
```

**2. Dispatch from Swift:**

```swift
LaravelBridge.shared.send?(
    "Native\\Mobile\\Events\\Your\\ThingHappened",
    [
        "result": "success",
        "id": id
    ]
)
```

**3. Listen in PHP:**

```php
#[On('native:Native\Mobile\Events\Your\ThingHappened')]
public function handleThing($result, $id = null)
{
    // Handle event
}
```

**4. Listen in JavaScript:**

```javascript
on(NativeEvents.Your.ThingHappened, (event) => {
    console.log('Result:', event.result);
});
```

**5. Add to NativeEvents constant:**

```javascript
export const NativeEvents = {
    // ... existing events ...
    Your: {
        ThingHappened: 'Native\\Mobile\\Events\\Your\\ThingHappened',
    }
};
```

---

## Best Practices

### 1. Threading

**UI Operations - Main Thread:**
```swift
DispatchQueue.main.async {
    // Present alerts, show UI
}
```

**Background Work:**
```swift
DispatchQueue.global(qos: .utility).async {
    // Long-running operations
    // Send results via events
}
```

### 2. Parameter Validation

Always validate parameters:

```swift
// Required parameter
guard let url = parameters["url"] as? String else {
    throw BridgeError.invalidParameters("Missing 'url' parameter")
}

// Optional with default
let timeout = parameters["timeout"] as? Int ?? 30

// Optional that may be nil
let id = parameters["id"] as? String
```

### 3. Error Handling

Use appropriate error types:

```swift
// Missing parameters
throw BridgeError.invalidParameters("Missing 'email'")

// Permission issues
throw BridgeError.permissionDenied("Camera access denied")
throw BridgeError.permissionRequired("Microphone permission needed")

// Execution errors
throw BridgeError.executionFailed("Network request failed: \(error)")
```

### 4. Namespacing

Organize by feature:

```
Device.GetId
Device.GetInfo
Device.GetBatteryInfo
Device.GetThermalState
Device.Vibrate
Device.ToggleFlashlight

Camera.GetPhoto
Camera.RecordVideo
Camera.PickMedia

Dialog.Alert
Dialog.Toast
```

### 5. Documentation

Always document your functions:

```swift
/// Show native alert dialog
/// Parameters:
///   - title: (required) string - Alert title
///   - message: (required) string - Alert message
///   - buttons: (optional) array - Button labels (default: ["OK"])
///   - id: (optional) string - Unique identifier
///   - event: (optional) string - Custom event class
///
/// Returns event: Native\Mobile\Events\Alert\ButtonPressed
///
/// Usage Example:
///   nativephp_call('Dialog.Alert', '{"title": "Hello", "message": "World"}')
class Alert: BridgeFunction {
    // ...
}
```

---

## Examples

### Example 1: Synchronous Function (Device Info)

```swift
class GetInfo: BridgeFunction {
    func execute(parameters: [String: Any]) throws -> [String: Any] {
        let device = UIDevice.current

        return [
            "name": device.name,
            "model": device.model,
            "platform": "ios",
            "osVersion": device.systemVersion,
            "manufacturer": "Apple"
        ]
    }
}
```

**PHP:**
```php
$info = Device::getInfo();
```

**JavaScript:**
```javascript
const info = await device.getInfo();
```

### Example 2: Async Function (Camera)

```swift
class GetPhoto: BridgeFunction {
    func execute(parameters: [String: Any]) throws -> [String: Any] {
        let id = parameters["id"] as? String
        let event = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Camera\\PhotoTaken"

        DispatchQueue.main.async {
            // Present camera UI
            // On completion, dispatch event:
            LaravelBridge.shared.send?(event, [
                "path": photoPath,
                "mimeType": "image/jpeg",
                "id": id
            ])
        }

        return ["acknowledged": true]
    }
}
```

**PHP:**
```php
// Trigger
nativephp_call('Camera.GetPhoto', '{"id": "avatar"}');

// Listen
#[On('native:Native\Mobile\Events\Camera\PhotoTaken')]
public function handlePhoto($path, $mimeType = null, $id = null)
{
    if ($id === 'avatar') {
        $this->avatarUrl = $path;
    }
}
```

**JavaScript:**
```javascript
// Trigger
await camera.getPhoto({ id: 'avatar' });

// Listen
on(NativeEvents.Camera.PhotoTaken, (event) => {
    if (event.id === 'avatar') {
        updateAvatar(event.path);
    }
});
```

### Example 3: Permission Handling

```swift
class Prompt: BridgeFunction {
    func execute(parameters: [String: Any]) throws -> [String: Any] {
        let id = parameters["id"] as? String
        let event = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Biometric\\Completed"

        DispatchQueue.main.async {
            let context = LAContext()
            var error: NSError?

            guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error) else {
                LaravelBridge.shared.send?(event, [
                    "success": false,
                    "error": error?.localizedDescription ?? "Biometric not available",
                    "id": id
                ])
                return
            }

            context.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, localizedReason: "Authenticate") { success, error in
                LaravelBridge.shared.send?(event, [
                    "success": success,
                    "error": error?.localizedDescription,
                    "id": id
                ])
            }
        }

        return ["acknowledged": true]
    }
}
```

---

## Third-Party Plugin Support

### Allowing Users to Register Functions

Users can register their own plugin functions:

**Swift (in their plugin package):**
```swift
public func registerMyPluginFunctions() {
    let registry = BridgeFunctionRegistry.shared

    registry.register("MyPlugin.DoSomething", function: MyPluginFunction())
}
```

**Register in BridgeFunctionRegistration.swift:**
```swift
func registerBridgeFunctions() {
    // ... core registrations ...

    // Third-party plugins
    #if canImport(MyPlugin)
    registerMyPluginFunctions()
    #endif
}
```

**PHP (in their plugin):**
```php
class MyPlugin
{
    public function doSomething(): mixed
    {
        return nativephp_call('MyPlugin.DoSomething', '{}');
    }
}
```

---

## Migration from Old C Bridge

Old `@_cdecl` functions can coexist during migration:

**Old Way:**
```swift
@_cdecl("NativePHPShowAlert")
public func NativePHPShowAlert(...) {
    // Old implementation
}
```

**New Way:**
```swift
class Alert: BridgeFunction {
    func execute(parameters: [String: Any]) throws -> [String: Any] {
        // New implementation
    }
}
```

Both work simultaneously. Gradually migrate PHP code to use `nativephp_call()`.

---

## Debugging

### Enable Logging

The bridge logs all operations:

```
🔌 Registered bridge function: Device.GetInfo
✅ Registered 42 bridge functions

🔍 NativePHPCan('Device.GetInfo'): YES
🚀 NativePHPCall('Device.GetInfo') with parameters: {}
✅ NativePHPCall succeeded
```

### Add Custom Logging

```swift
func execute(parameters: [String: Any]) throws -> [String: Any] {
    print("🐛 Parameters: \(parameters)")

    let result = doWork()

    print("🐛 Result: \(result)")

    return result
}
```

---

## Summary

### The God Method Pattern

1. **One entry point**: `nativephp_call(method, params)`
2. **Dynamic registration**: Functions registered at runtime
3. **Type-safe**: Protocol-based with error handling
4. **Event-driven**: Async operations via events
5. **Extensible**: Third-party plugins supported

### Key Benefits

- No recompilation needed for new features
- Consistent API across all platforms
- Clean separation of concerns
- Easy testing and debugging
- Plugin ecosystem support

### File Structure

```
Bridge/
├── BridgeRouter.swift              # Core routing logic
├── BridgeFunctionRegistration.swift  # Function registration
├── NativePHP.swift                 # Legacy support + LaravelBridge
└── Functions/
    ├── DeviceFunctions.swift
    ├── CameraFunctions.swift
    ├── DialogFunctions.swift
    └── YourFunctions.swift         # Add your functions here
```

---

**Ready to build!** Follow this guide to create powerful native integrations without touching the PHP binary compilation. 🚀