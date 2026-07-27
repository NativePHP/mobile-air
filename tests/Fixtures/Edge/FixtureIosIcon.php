<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Icon\IosSymbol;

/** Stand-in for the plugin's generated `Ios` SF Symbols catalog. */
enum FixtureIosIcon: string implements IosSymbol
{
    case Star = 'star.fill';
    case Plus = 'plus';
    case Tray = 'tray.full';
}
