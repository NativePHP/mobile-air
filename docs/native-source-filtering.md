# Native Source Filtering

## Overview

NativePHP Mobile plugins can now opt-in to controlling which of their native source files get compiled into the app. This enables build-time tree-shaking for plugins that ship optional components, improving app size and build cleanliness.

## Use Case

The primary use case is plugins that ship multiple components or features where the consuming app only needs a subset. For example, a UI component library that ships 50 components but an app only uses 3 of them - the unused 47 components' native code should not be compiled into the final app binary.

**First consumer:** NativePHP/mobile-ui component tree-shaking ([PR #127](https://github.com/NativePHP/mobile-ui/pull/127))

## Plugin Manifest Configuration

To enable native source filtering, add two entries to your plugin's `nativephp.json`:

### Android

```json
{
  "android": {
    "manages_native_sources": true
  },
  "hooks": {
    "native_sources": "your-plugin:filter-sources"
  }
}
```

### iOS

```json
{
  "ios": {
    "manages_native_sources": true
  },
  "hooks": {
    "native_sources": "your-plugin:filter-sources"
  }
}
```

Both platforms can share the same hook command, or use different commands if needed.

## Hook Contract

When `manages_native_sources` is set to `true`, NativePHP Core will invoke the `native_sources` hook command once per platform being compiled.

### Hook Arguments

The hook command receives:

- `--platform=ios|android` - The platform being compiled
- `--build-path` - Path to the native build directory
- `--plugin-path` - Path to the plugin directory
- `--app-id` - The app's bundle identifier

### Hook Output

The hook command must:

1. **Print a JSON array to stdout** containing file paths to compile
2. **Exit with code 0** on success

#### File Path Format

File paths can be:

- **Relative** to the plugin's native source root for that platform:
  - Android: `resources/android/src/` (or `resources/android/` for flat structure)
  - iOS: `resources/ios/Sources/` (or `resources/ios/` for flat structure)
- **Absolute** paths within the plugin directory

#### Examples

**Single files:**
```json
["Component1.kt", "Component2.kt"]
```

**Directories (all matching files will be copied):**
```json
["components/badges", "renderers/text"]
```

**Mixed:**
```json
["SharedTypes.swift", "components/Button.swift", "utils/"]
```

### Example Hook Implementation

```php
<?php

namespace YourVendor\YourPlugin\Console\Commands;

use Illuminate\Console\Command;

class FilterNativeSourcesCommand extends Command
{
    protected $signature = 'your-plugin:filter-sources
                            {--platform= : Platform being compiled (ios|android)}
                            {--build-path= : Path to the native build directory}
                            {--plugin-path= : Path to the plugin directory}
                            {--app-id= : The app bundle identifier}';

    protected $description = 'Filter native source files for compilation';

    public function handle(): int
    {
        $platform = $this->option('platform');
        
        // Get the list of components the app is actually using
        // (from config, scan usage, etc.)
        $usedComponents = config('your-plugin.components', []);
        
        // Map components to their source files
        $sources = [];
        
        foreach ($usedComponents as $component) {
            if ($platform === 'android') {
                $sources[] = "components/{$component}Renderer.kt";
            } else {
                $sources[] = "components/{$component}Renderer.swift";
            }
        }
        
        // Always include shared/base files
        $sources[] = $platform === 'android' 
            ? 'BaseRenderer.kt' 
            : 'BaseRenderer.swift';
        
        // Output as JSON
        $this->line(json_encode(array_values(array_unique($sources))));
        
        return 0;
    }
}
```

## Failure Policy

If the hook fails in any of the following ways, **Core will fall back to copying ALL native sources** with a clear warning:

- Hook exits with non-zero code
- Hook times out
- Hook returns invalid JSON
- Hook returns an empty array `[]`
- Hook references a file that doesn't exist
- Hook references a path outside the plugin directory (path traversal attempt)

This ensures that a broken or misconfigured hook **never produces a partial or broken build** - it simply becomes a no-op and compilation proceeds as if the flag were absent.

### Example Warnings

```
Plugin 'vendor/plugin': native_sources hook exited 1 — falling back to copying all sources
Plugin 'vendor/plugin': native_sources hook returned invalid JSON — falling back to copying all sources
Plugin 'vendor/plugin': native_sources hook returned an empty list — falling back to copying all sources
Plugin 'vendor/plugin': native_sources hook referenced non-existent file 'Missing.kt' — falling back to copying all sources
Plugin 'vendor/plugin': native_sources hook returned path with '..' (path traversal) — falling back to copying all sources
```

## Compilation Behavior

### Without the flag

Plugins without `manages_native_sources: true` behave exactly as before:
- **Android:** All `.kt` files in `resources/android/src/` are copied and compiled
- **iOS:** All Swift files in `resources/ios/Sources/` are copied (excluding test targets, build artifacts, and Package.swift)

### With the flag + successful hook

Only the files returned by the hook are copied and compiled:
- File paths are resolved relative to the plugin's native source root
- Directories are walked recursively for matching source files
- Everything else is ignored

### With the flag + failed hook

Falls back to copying all sources (same as "without the flag" behavior) with a warning explaining what went wrong.

## iOS: Priority with `ios.sources`

If a plugin declares **both** `manages_native_sources: true` and an explicit `ios.sources` list in the manifest, **the explicit list wins** and the hook is not invoked for iOS.

This allows plugins to:
- Use compile-time filtering via hook for Android
- Use declarative filtering via manifest for iOS

Example:
```json
{
  "android": {
    "manages_native_sources": true
  },
  "ios": {
    "manages_native_sources": true,
    "sources": ["OnlyThisFile.swift"]
  },
  "hooks": {
    "native_sources": "plugin:filter-sources"
  }
}
```

In this case:
- **Android:** Hook is invoked
- **iOS:** Only `OnlyThisFile.swift` is copied (hook is NOT invoked)

## Testing

The feature includes comprehensive test coverage for both Android and iOS:

- ✅ Flag absent => unchanged behavior (all sources copied)
- ✅ Flag + valid list => only listed files copied
- ✅ Hook exits non-zero => fallback with warning
- ✅ Hook returns empty list => fallback with warning
- ✅ Hook returns invalid JSON => fallback with warning
- ✅ Hook returns path with `..` => rejected with fallback
- ✅ Hook references non-existent file => fallback with warning
- ✅ Hook returns directory => all matching files in that directory copied

See:
- `tests/Feature/Plugins/AndroidCompilerTest.php`
- `tests/Feature/Plugins/IOSCompilerTest.php`

## Migration

No migration needed - this is a new opt-in feature. Existing plugins continue to work unchanged.

To adopt it:
1. Add `"manages_native_sources": true` to your platform config
2. Add the `"native_sources"` hook to your hooks
3. Implement the hook command
4. Test that the hook returns valid JSON and exits 0
5. Verify the filtered build works correctly

## Security

Path traversal attempts are explicitly rejected:
- Any path containing `..` is rejected with a fallback warning
- Paths are validated to be within the plugin's source directory using `realpath()` checks
- This prevents a malicious plugin from reading or influencing files outside its own directory
