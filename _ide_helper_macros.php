<?php

/**
 * IDE helper for the routing macros NativeServiceProvider registers at boot.
 *
 * Route::native() / Route::nativeGroup() / ->layout() are runtime macros, so
 * static analysis ("Method 'native' not found in Illuminate\Routing\Router")
 * can't see them. This file re-declares the host classes with the macro
 * signatures in their docblocks; IDEs merge the declarations and resolve the
 * calls. It is intentionally outside src/ — composer's autoloader (psr-4:
 * src/) and phpstan (paths: src/) never touch it, so nothing here executes
 * or double-declares at runtime.
 *
 * Keep the signatures in sync with the Route::macro(...) registrations in
 * src/NativeServiceProvider.php.
 */

namespace Illuminate\Routing {

    /**
     * @method \Illuminate\Routing\Route native(string $uri, string $componentClass) Register a native screen route for a NativeComponent class
     * @method void nativeGroup(string $layout, \Closure $routes) Register native routes that inherit the given layout unless they override it with ->layout()
     */
    class Router {}

    /**
     * @method $this layout(string $layoutClass) Set the navigation layout (StackLayout, TabsLayout, …) for this native route
     */
    class Route {}
}

namespace Illuminate\Support\Facades {

    /**
     * @method static \Illuminate\Routing\Route native(string $uri, string $componentClass) Register a native screen route for a NativeComponent class
     * @method static void nativeGroup(string $layout, \Closure $routes) Register native routes that inherit the given layout unless they override it with ->layout()
     */
    class Route {}
}
