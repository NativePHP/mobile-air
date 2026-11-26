<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Marks a method as one that something outside the app, for example a
 * server, may ask the component to run.
 *
 *     #[ClientAction]
 *     public function refresh(): void
 *     {
 *         // ...
 *     }
 *
 * A template may name any public method on its component. That is fine
 * while the only thing naming methods is the app's own Blade. It stops
 * being fine once the name and arguments arrive from outside the app:
 * the callable surface would be every public method the component
 * happens to have.
 *
 * So a call with ActionSource::Remote may only reach methods carrying
 * this attribute, and only with plain-value arguments. See
 * ComponentMethodInvoker::assertRemoteCallable().
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ClientAction {}
