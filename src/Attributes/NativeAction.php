<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Marks a method as dispatchable from a server-rendered fragment.
 *
 *     #[NativeAction]
 *     public function refresh(): void
 *     {
 *         // ...
 *     }
 *
 * Client-side callbacks resolve through a bare `method_exists()` check,
 * which means any public method on the component. That is fine while the
 * only thing naming a method is the app's own Blade. It stops being fine
 * once the expression arrives from a server (docs/ASGARD-PROTOCOL.md
 * §10): the callable surface would be every public method the component
 * happens to have.
 *
 * So a fragment may only name methods carrying this attribute. Nothing
 * dispatches on it yet — that is the event workstream — but the marker
 * ships now so components can be written against the final rule instead
 * of being retrofitted later.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class NativeAction {}
