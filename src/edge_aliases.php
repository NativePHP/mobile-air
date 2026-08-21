<?php

/**
 * Back-compat aliases for namespaces that moved into `supanative/core`.
 * ====================================================================
 *
 * WHAT
 * ----
 * The platform-neutral half of the Edge layer moved out of this package into
 * `supanative/core`, under three prefixes:
 *
 *     Native\Mobile\Edge\Element             → SupaNative\Core\Edge\Element
 *     Native\Mobile\Edge\CallbackRegistry    → SupaNative\Core\Edge\CallbackRegistry
 *     Native\Mobile\Edge\Elements\Column     → SupaNative\Core\Edge\Elements\Column
 *     ... and ~60 more, mirroring the same subdirectory layout
 *     Native\Mobile\Attributes\Computed      → SupaNative\Core\Attributes\Computed
 *     Native\Mobile\Support\NativeCallbacks  → SupaNative\Core\Support\NativeCallbacks
 *
 * The platform-neutral half of `native:watch` followed it, for the same reason
 * and under two more prefixes:
 *
 *     Native\Mobile\Concerns\ManagesWatchman        → SupaNative\Core\Concerns\ManagesWatchman
 *     Native\Mobile\Concerns\ManagesPollingWatcher  → SupaNative\Core\Concerns\ManagesPollingWatcher
 *     Native\Mobile\Support\Watch\WatchConsole      → SupaNative\Core\Support\Watch\WatchConsole
 *     Native\Mobile\Exceptions\WatchPromptCancelled → SupaNative\Core\Exceptions\WatchPromptCancelled
 *
 * Everything that stayed behind (NativeComponent, the chrome elements,
 * Layouts, NativeRouter, `Attributes\OnNative`, the rest of `Support`) keeps
 * its `Native\Mobile\*` name. This file makes the ones that MOVED still answer
 * to their old names, so nothing downstream had to be touched in the same
 * change.
 *
 * `Concerns\InteractsWithWatchTerminal` is this move's non-entry, and it is the
 * NativeComponent story again: it split rather than moved, so this package still
 * ships a real trait of that name which composes core's and adds the two mobile
 * actions (reload, navigate) the terminal dispatches to. Composer finds it and
 * the probe below refuses it.
 *
 * `NativeComponent` is the interesting non-entry. It split rather than moved:
 * the neutral half is core's abstract class and this package's is a real
 * subclass carrying the mobile traits. So there is nothing to alias — Composer
 * finds the real class and the probe below never fires for that name. Same
 * story for `NavigationIntent`, which subclasses core's to add RESTART and
 * EXIT_WEB.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * `nativephp/mobile-ui` alone references the old names from 98 files —
 * `Edge\Element`, `Edge\Components\Native\NativeBladeComponent`,
 * `Edge\CallbackRegistry`, `Edge\TailwindParser`. Third-party UI plugins,
 * `native:plugin` scaffolds, and every app screen ever written
 * (`public function render(): Element`) do the same. Renaming across all of
 * them in lockstep with this move is not possible: they ship on their own
 * release cadences, and an app can perfectly well hold an old mobile-ui and
 * a new mobile at the same time. The aliases are what make that combination
 * work, and they are the compatibility proof for the extraction — mobile-ui
 * was deliberately not edited at all.
 *
 * This package's own 28 Edge test files also still use the old names, on
 * purpose: they exercise the alias path on every run, so a broken alias is
 * a red test rather than a support ticket.
 *
 * WHY IT IS AN AUTOLOADER AND NOT ~60 EAGER class_alias() CALLS
 * ------------------------------------------------------------
 * `class_alias()` has to load its target to alias it, so an eager loop
 * would pull the whole element layer into memory on every request —
 * including plain web requests that never render a native screen. This
 * registers an autoloader instead, so an app that never touches the old
 * namespace pays nothing at all.
 *
 * The `Native\Mobile\` PSR-4 prefix maps to `src/`, so Composer's
 * autoloader looks for `src/Edge/Element.php`, doesn't find it, and PHP
 * moves on to the next registered autoloader — this one. Nothing needs
 * unregistering, and the only ordering requirement is running after
 * Composer's, which appending gives us. That ordering is also what keeps a
 * class that STAYED (`Attributes\OnNative`, `Support\PhpBinaries`) winning
 * over an alias: Composer gets first refusal on every name.
 *
 * WHY IT THEN ALIASES THE *WHOLE* SET AT ONCE
 * -------------------------------------------
 * This is the subtle part, and getting it wrong produces TypeErrors that
 * look impossible.
 *
 * PHP does NOT autoload when it verifies a value against a parameter,
 * return, or property type declaration. Checking `$x instanceof T` at a
 * type boundary uses a no-autoload class lookup: if `T` isn't loaded yet,
 * the engine reasons that `$x` cannot possibly be a `T` (for real
 * inheritance that is sound — a subclass can't exist without its parent
 * being loaded) and raises a TypeError. `class_alias()` breaks that
 * assumption, because the alias and the target are the same class under two
 * names and neither implies the other is loaded.
 *
 * So aliasing strictly one name at a time is not enough. Concretely, in
 * mobile-ui today:
 *
 *     // Native\Mobile\UI\Builders\Drawer — a plain class, extends nothing
 *     use Native\Mobile\Edge\Element;
 *     public static function make(View|Element $content): self
 *
 * Nothing about loading `Drawer` forces `Native\Mobile\Edge\Element` to
 * resolve, so handing it a (core) Element would fail the type check. The
 * cases that DO work by themselves are the ones where the old name appears
 * in an `extends`/`implements` clause, or in a signature that overrides a
 * parent's — inheritance and variance checks both autoload.
 *
 * Hence: the first request for ANY old Edge name aliases the entire moved
 * set. The trigger stays lazy (nothing happens until the old namespace is
 * actually used), but once it fires, every old name exists and load order
 * stops mattering.
 *
 * The set is discovered by walking core's own `src/Edge` directory rather
 * than from a list kept here, which would silently drift the first time
 * core gains, renames, or moves back an element. A name that also exists in
 * this package is left alone — see the ownership check below, which is not
 * merely an optimisation.
 *
 * WHY A NAME THIS PACKAGE STILL OWNS IS REFUSED OUTRIGHT
 * -----------------------------------------------------
 * Relying on "Composer is registered first, so it wins" is not enough, and
 * PHPStan is what proves it. Its autoload source locator resolves a class by
 * invoking the autoloader chain with a stream wrapper that RECORDS the file
 * each autoloader reaches for and then refuses the read — so the class is
 * never actually defined, and PHP dutifully carries on to the next autoloader.
 * That next autoloader is this one. Alias the name there and PHPStan concludes
 * `Native\Mobile\Edge\NavigationIntent` *is* `SupaNative\Core\Edge\
 * NavigationIntent`, at which point `NavigationIntent::EXIT_WEB` — a constant
 * only the subclass declares — is reported as undefined across the package.
 *
 * Until the NativeComponent split there was no name in both packages, so this
 * never came up. Now there are two (`NativeComponent`, `NavigationIntent`) and
 * the rule has to be explicit: if `src/` holds a file for the name, this
 * package owns it and no alias may ever shadow it.
 *
 * WHY autoload.files RATHER THAN THE SERVICE PROVIDER
 * ---------------------------------------------------
 * Registration has to happen before any class in the old namespace is
 * touched. A service provider boots too late and too conditionally: unit
 * tests that new up an Element without a container, `--no-dev` artisan
 * calls, and package-discovery-less contexts would all miss it.
 * `autoload.files` runs at `vendor/autoload.php` time, which is
 * unconditional.
 *
 * WHEN THIS CAN GO
 * ----------------
 * When every consumer references the `SupaNative\Core\Edge\*` names
 * directly. In practice that means: mobile-ui released with new imports, the
 * `native:plugin` scaffold emitting new imports, a major version of this
 * package to hang the break off, and — because plugins are compiled into
 * apps by name — a deprecation window long enough for the plugin ecosystem
 * to catch up. Deleting this file is a breaking change; treat it as one.
 *
 * There is deliberately no deprecation warning here: it would fire from
 * inside mobile-ui's own code, where an app developer can do nothing about
 * it, on every render.
 */
/**
 * Whether this package still ships a real class file for `$class`.
 *
 * The `Native\Mobile\` PSR-4 prefix maps to `src/` (this directory), so the
 * name translates straight to a path. True means: hands off, Composer resolves
 * this one for real.
 */
$nativePhpMobileOwnsClass = static function (string $class): bool {
    $prefix = 'Native\\Mobile\\';

    if (! str_starts_with($class, $prefix)) {
        return false;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));

    return is_file(__DIR__.DIRECTORY_SEPARATOR.$relative.'.php');
};

spl_autoload_register(function (string $class) use ($nativePhpMobileOwnsClass): void {
    static $aliasedWholeSet = false;

    // Every prefix that moved, old → new. Only the Edge one gets the
    // whole-set treatment below: the type-declaration hazard it exists for
    // needs a class to appear in a signature, and neither an attribute (read
    // back through reflection, which resolves the literal name) nor a
    // static-only support class ever does.
    $prefixes = [
        'Native\\Mobile\\Edge\\' => 'SupaNative\\Core\\Edge\\',
        'Native\\Mobile\\Attributes\\' => 'SupaNative\\Core\\Attributes\\',
        'Native\\Mobile\\Support\\' => 'SupaNative\\Core\\Support\\',
        'Native\\Mobile\\Concerns\\' => 'SupaNative\\Core\\Concerns\\',
        'Native\\Mobile\\Exceptions\\' => 'SupaNative\\Core\\Exceptions\\',
    ];

    $oldPrefix = null;
    $newPrefix = null;

    foreach ($prefixes as $old => $new) {
        if (str_starts_with($class, $old)) {
            $oldPrefix = $old;
            $newPrefix = $new;
            break;
        }
    }

    if ($oldPrefix === null || $nativePhpMobileOwnsClass($class)) {
        return;
    }

    $suffix = substr($class, strlen($oldPrefix));
    $target = $newPrefix.$suffix;

    // Autoloading is left ON in these probes: $target never carries this
    // callback's prefix, so we cannot recurse into ourselves. class_exists()
    // also answers true for enums (all five alignment enums moved), so only
    // interfaces and traits need naming separately.
    //
    // If the name doesn't exist in core either, nothing is aliased and PHP
    // raises its usual "class not found" against the ORIGINAL name — which is
    // the error the caller needs to see.
    if (class_exists($target) || interface_exists($target) || trait_exists($target)) {
        class_alias($target, $class);
    }

    // The whole-set sweep is the Edge namespace's alone — see the note on
    // $prefixes above.
    if ($aliasedWholeSet || $oldPrefix !== 'Native\\Mobile\\Edge\\') {
        return;
    }

    $aliasedWholeSet = true;

    // Core's Edge root, from core's own Element (a root-level Edge class, so
    // one dirname lands exactly on src/Edge). If core isn't installed there
    // is nothing to alias and nothing to do.
    if (! class_exists($newPrefix.'Element')) {
        return;
    }

    $edgeRoot = dirname((new \ReflectionClass($newPrefix.'Element'))->getFileName());

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($edgeRoot, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($edgeRoot) + 1, -4);
        $name = $oldPrefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if ($name === $class) {
            continue;
        }

        // Skipped here as well as refused above, because the refusal happens
        // AFTER Composer has already loaded the real class. `NativeComponent`
        // shows why that matters: asking for it would drag this package's
        // subclass, its four mobile traits and Platform into a plain web
        // request that only wanted an Element.
        if ($nativePhpMobileOwnsClass($name)) {
            continue;
        }

        // Routed back through this autoloader (the static latch above stops
        // it re-scanning), so the aliasing logic lives in exactly one place.
        class_exists($name);
    }
});
