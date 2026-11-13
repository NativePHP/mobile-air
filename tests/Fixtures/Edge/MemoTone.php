<?php

namespace Tests\Fixtures\Edge;

/** An enum prop for the #[Memo] tests: immutable, so compared as it is. */
enum MemoTone: string
{
    case Calm = 'calm';
    case Loud = 'loud';
}
