<?php

namespace Native\Mobile\Edge;

use SupaNative\Core\Edge\NavigationIntent as CoreNavigationIntent;

/**
 * The two intent types that exist only because a mobile app is a native shell
 * wrapped around a WebView, with a dev-time hot reload bolted to the side.
 *
 * Navigate / back / replace live in core — every host can do those. These two
 * cannot be hoisted with them: RESTART asks the *runtime* to reboot (Kotlin
 * re-executes PHP from scratch and replays the stack), and EXIT_WEB abandons
 * the native screen stack for a plain Laravel route rendered in the WebView.
 * Neither has a desktop meaning.
 *
 * Subclassing a value object is unusual, and worth the one line of surprise:
 * `$type` stays a plain string, so `NativeRouter`'s `match ($intent->type)`
 * handles core-minted and mobile-minted intents identically, and a component
 * can keep constructing `new NavigationIntent(...)` without caring which half
 * of the framework named the constant it passed.
 */
class NavigationIntent extends CoreNavigationIntent
{
    /**
     * Hot reload: PHP exits and the native side re-executes it. Carries the
     * URI to land on, with the stack beneath it restored from the file
     * `hotRestartPayload()` wrote.
     */
    const RESTART = 'restart';

    /** Leave the native screen stack for a plain web URL in the WebView. */
    const EXIT_WEB = 'exit_web';
}
