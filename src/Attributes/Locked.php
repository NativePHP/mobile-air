<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Marks a public property as not settable through state-sync bindings.
 *
 *     #[Locked]
 *     public int $userId;
 *
 * Two-way binding (`native:model`, and anything else that routes
 * through __syncProperty) throws LockedPropertyException instead of
 * writing the property — a guardrail for identity-ish props (ids,
 * owner keys, tenant scopes) that must only ever change server-side.
 * Component methods may still assign the property freely.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Locked {}
