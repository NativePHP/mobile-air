<?php

use Native\Mobile\Edge\Concerns\HasVirtualListWindow;

function virtualListScreen(): object
{
    return new class
    {
        use HasVirtualListWindow;
    };
}

it('moves the window to hold the row it scrolls to, keeping the window size', function () {
    $screen = virtualListScreen();

    $screen->scrollVirtualListTo(2500);

    // Default window is 0..79 (80 rows); a quarter of it stays above the row.
    expect($screen->virtualWindowFrom)->toBe(2481)
        ->and($screen->virtualWindowTo)->toBe(2560)
        ->and($screen->virtualScrollTo)->toBe(2500);
});

it('keeps the window at the start for a row near the top', function () {
    $screen = virtualListScreen();
    $screen->setVirtualWindow(300, 339);

    $screen->scrollVirtualListTo(5);

    expect($screen->virtualWindowFrom)->toBe(0)
        ->and($screen->virtualWindowTo)->toBe(39)
        ->and($screen->virtualScrollTo)->toBe(5);
});

it('has no row to scroll to until one is asked for', function () {
    expect(virtualListScreen()->virtualScrollTo)->toBeNull();
});
