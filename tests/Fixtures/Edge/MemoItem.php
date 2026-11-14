<?php

namespace Tests\Fixtures\Edge;

/** A plain object prop for the #[Memo] tests: mutable, so compared by value. */
class MemoItem
{
    public function __construct(public string $label) {}
}
