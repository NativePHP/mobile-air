<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Icon\AndroidSymbol;

/** Stand-in for the plugin's generated Material catalog (outlined variant). */
enum FixtureAndroidIcon: string implements AndroidSymbol
{
    case StarOutline = 'star_outline';
    case Add = 'add';
    case Inbox = 'inbox';

    public function variant(): string
    {
        return 'outlined';
    }
}
