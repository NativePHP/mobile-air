<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Marks a public property as locked on the web target.
 *
 *     #[Locked]
 *     public int $userId;
 *
 * The value still travels inside the snapshot, but the whole snapshot is
 * HMAC-sealed (see EdgeSnapshot): any client-side tamper — of a locked
 * prop or anything else — fails the integrity check with a 419 before
 * hydration. So today #[Locked] is a declaration of intent rather than an
 * extra enforcement layer: server-side dispatch handlers may still change
 * a locked prop freely, and hydration restores it from the (verified)
 * snapshot like any other prop.
 *
 * It exists now so components can annotate identity-ish props (ids, owner
 * keys) and so later phases (e.g. partial/unsealed updates, model
 * key-and-refetch support) can enforce stronger semantics without an API
 * change.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Locked {}
