# Tab presentation extension (documentation draft)

> This is a draft for the NativePHP Mobile v4 documentation site. It should
> ship with the first NativePHP release that contains this API.

NativePHP plugins can register alternative visual presentations for a
layout's native bottom tabs. NativePHP continues to own `NativeRootTabs`, tab
selection, callbacks, per-tab navigation stacks, transitions, back handling,
safe-area placement, keyboard integration, and screen lifecycle.

## Select a presentation in PHP

```php
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;

return TabBar::make()
    ->add(Tab::link('Home', '/home', icon: 'home'))
    ->add(Tab::link('Profile', '/profile', icon: 'person'))
    ->presentation('acme:floating', [
        'cornerRadius' => 24,
        'showsLabels' => true,
    ]);
```

Identifiers are exact, case-sensitive registry keys. They must be lowercase,
at most 128 bytes, may use letters, numbers, dots, dashes, underscores, and
one namespace colon. `system` explicitly selects NativePHP's stock bar.

Configuration must be a JSON object containing only JSON-compatible scalar,
array, object, and null values. The maximum nesting depth is eight and the
encoded maximum is 16 KiB. PHP object instances, closures, resources, invalid
UTF-8, and non-finite numbers are rejected before a tree is published.

An absent identifier, `system`, an unknown registration, or malformed native
configuration uses the stock system bar. Existing applications therefore
retain their current behavior.

## Register on iOS

Use the existing plugin initializer field in `nativephp.json`. No component,
bridge call, or delayed lifecycle callback is required:

```json
{
    "namespace": "Acme\\FloatingTabs",
    "ios": {
        "init_function": "registerAcmeTabPresenters"
    }
}
```

Declare that top-level function in the plugin's compiled Swift sources. NativePHP
calls it synchronously during native plugin initialization, before the first tab
tree is rendered:

```swift
import SwiftUI

public func registerAcmeTabPresenters() {
    NativeTabPresentationRegistry.shared.register(identifier: "acme:floating") { context in
        AnyView(
            HStack {
                ForEach(context.tabs) { tab in
                    Button {
                        context.activate(tab.id)
                    } label: {
                        Label(tab.label, systemImage: tab.icon)
                    }
                    .accessibilityLabel(tab.accessibilityLabel)
                }
            }
        )
    }
}
```

The returned view is placed by NativePHP using a bottom safe-area inset. The
system tab strip is hidden visually and from accessibility while the original
`TabView` remains mounted.

## Register on Android

Point the existing Android initializer field at a fully-qualified top-level
Kotlin function:

```json
{
    "namespace": "Acme\\FloatingTabs",
    "android": {
        "init_function": "com.acme.floatingtabs.registerAcmeTabPresenters"
    }
}
```

```kotlin
package com.acme.floatingtabs

import android.content.Context
import androidx.compose.foundation.layout.Row
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import com.nativephp.mobile.ui.nativerender.NativeTabPresentationRegistry

fun registerAcmeTabPresenters(context: Context) {
    NativeTabPresentationRegistry.register("acme:floating") { presentation ->
        Row {
            presentation.tabs.forEach { tab ->
                TextButton(onClick = { presentation.activate(tab.id) }) {
                    Text(tab.label)
                }
            }
        }
    }
}
```

The presenter occupies the existing `Scaffold.bottomBar` slot. Scaffold owns
the relevant navigation-bar and IME insets; plugins should not create another
navigation container.

## Presenter contract

Both platforms expose the same concepts:

- immutable descriptors with stable ID, label, accessibility label, icon,
  badge/news state, and navigation/search/action role;
- the authoritative selected tab ID;
- typed access to the validated configuration object;
- the current visual visibility state; and
- `activate(id)`, the only supported way to request tab activation.

Presenters must render controls with appropriate accessibility labels and
touch targets. They must not dispatch bridge callbacks directly, change PHP
routes, create navigation stacks/controllers, or retain native tree nodes.
NativePHP ignores activation requests for IDs not present in the context.

Registration is process-wide and keyed by exact identifier. The first
registration wins; later collisions return `false` and produce a development
diagnostic. Multiple identifiers may coexist, and different layout groups may
select different presenters. Changing the presentation changes only the
visual bar and does not replace the root tabs or their navigation state.

## Visibility and compatibility

When a screen hides its tab bar, NativePHP keeps root tabs and coordinators
mounted but emits no visual custom presentation. Top-bar visibility remains
independent. Tab accessories remain core-owned and are placed above either
the system or custom presentation.

Plugin releases using this API must require the first NativePHP Mobile version
that ships it; publish that core version before publishing a dependent plugin.
Applications that do not select a custom presentation need no changes.

Register the plugin explicitly with
`php artisan native:plugin:register vendor/plugin-name`. The registration step
causes its Swift/Kotlin sources and initializer declarations to be included in
generated applications. Run `php artisan native:plugin:list` to verify the
registration, then rebuild with `php artisan native:run`. Applications whose
generated `nativephp/` project predates the NativePHP release containing this
registry must update the Composer dependency and regenerate native projects
with `php artisan native:install --force` before building; editing generated
Xcode or Android Studio files is not supported.
