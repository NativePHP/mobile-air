<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class SearchableScreen extends NativeComponent
{
    /** @var list<string> */
    public array $articles = ['Alpha release', 'Beta notes', 'Gamma rays'];

    public function onSearchQuery(string $query): array
    {
        return collect($this->articles)
            ->filter(fn (string $a) => stripos($a, $query) !== false)
            ->map(fn (string $a) => ['title' => $a, 'url' => '/'])
            ->values()
            ->all();
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('Searchable'));
    }
}
