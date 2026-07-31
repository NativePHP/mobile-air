<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Renders a placeholder instantly while the component's `mount()` runs,
 * so navigating to a screen with slow setup feels immediate.
 *
 *     #[Lazy]
 *     class Dashboard extends NativeComponent
 *     {
 *         public function mount(): void { ... slow data load ... }
 *
 *         protected function placeholder(): Element|View
 *         {
 *             return view('native.dashboard-skeleton');
 *         }
 *     }
 *
 * Override `placeholder()` to customize the loading frame; the default is
 * a centered activity indicator wrapped in the screen's layout chrome.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Lazy {}
