<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Marks a method as a computed property.
 *
 *     #[Computed]
 *     public function revenue(): int
 *     {
 *         return Order::sum('total');
 *     }
 *
 * Access it as `$this->revenue` (in PHP or the bound Blade view). The
 * method runs at most once per render frame and the result is memoized;
 * the cache is cleared at the top of each frame and whenever a bound
 * property changes via `__syncProperty`. Pass `persist: true` to keep the
 * value across frames (until a property sync or an explicit
 * `unset($this->revenue)`), e.g. for an expensive query that shouldn't
 * recompute on every re-render.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Computed
{
    public function __construct(public bool $persist = false) {}
}
