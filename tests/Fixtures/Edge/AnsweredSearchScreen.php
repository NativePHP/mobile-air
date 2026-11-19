<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** A screen with layout chrome (rendered through a View) that answers the search tab's queries. */
class AnsweredSearchScreen extends NativeComponent
{
    public function onSearchQuery(string $query): array
    {
        return str_starts_with('alpha', strtolower($query)) ? [['title' => 'Alpha']] : [];
    }

    public function render(): View
    {
        return view('chrome-screen');
    }
}
