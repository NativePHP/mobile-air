<?php

declare(strict_types=1);

namespace Native\Mobile\Commands;

enum ScreenshotCrop: string
{
    /** Keep only a strip nearest the top edge — everything else is discarded. */
    case Top = 'top';

    /** Keep only a strip nearest the bottom edge — everything else is discarded. */
    case Bottom = 'bottom';

    /** Trim a strip off both the top and bottom edges, keeping the middle. */
    case Both = 'both';
}
