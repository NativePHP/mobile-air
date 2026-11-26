<?php

namespace Tests\Fixtures\Edge;

/** A package trait whose public methods are plumbing. See ReservedTraitTest. */
trait PackagePlumbing
{
    public function packageSend(): string
    {
        return 'sent';
    }
}
