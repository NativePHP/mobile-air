<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Layouts\NativeLayout;

/**
 * Registry for plugin-provided **chrome contributors** — the PHP half of the
 * framework's chrome seam (paired with the native `NativeRootHostRegistry`).
 *
 * A contributor lets a plugin append a hoistable sentinel element to a screen's
 * published tree without core knowing what the chrome is. `wrapWithChrome()`
 * invokes every registered contributor and appends whatever element it returns
 * to the published root; a native root host (registered by the same plugin)
 * then pulls that sentinel out and renders the actual chrome.
 *
 * Each contributor is a callable:
 *
 *     fn(NativeComponent $screen, ?NativeLayout $layout, callable $renderPartial): ?Element
 *
 * - `$screen`        — the component being rendered (read its per-screen overrides).
 * - `$layout`        — the screen's layout instance, or null if it has none.
 * - `$renderPartial` — `fn(\Illuminate\View\View): Element`, renders a Blade view
 *                      through the screen's own bound path so `@press` / wire
 *                      bindings inside the chrome resolve against the screen.
 *   Return an Element to hoist, or null to contribute nothing.
 *
 * Plugins register from their service provider's boot, e.g. native-ui registers
 * the layout-drawer contributor here.
 */
class ChromeContributorRegistry
{
    /** @var array<int, callable> */
    protected static array $contributors = [];

    public static function register(callable $contributor): void
    {
        static::$contributors[] = $contributor;
    }

    /** @return array<int, callable> */
    public static function all(): array
    {
        return static::$contributors;
    }

    public static function reset(): void
    {
        static::$contributors = [];
    }

    /**
     * Run every contributor and collect the non-null sentinel elements they
     * produce, in registration order.
     *
     * @return array<int, Element>
     */
    public static function collect(NativeComponent $screen, ?NativeLayout $layout, callable $renderPartial): array
    {
        $elements = [];
        foreach (static::$contributors as $contributor) {
            $element = $contributor($screen, $layout, $renderPartial);
            if ($element instanceof Element) {
                $elements[] = $element;
            }
        }

        return $elements;
    }
}
