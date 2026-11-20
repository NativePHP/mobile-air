<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** A screen with layout chrome (rendered through a View) that answers the search tab. */
class SearchTabScreen extends NativeComponent
{
    public function onSearchQuery(string $query): array
    {
        return [['title' => 'Search for '.$query]];
    }

    public function onSearchSubmit(string $query): void
    {
        $this->navigate('/search?q='.urlencode($query));
    }

    public function render(): View
    {
        return view('chrome-screen');
    }
}
