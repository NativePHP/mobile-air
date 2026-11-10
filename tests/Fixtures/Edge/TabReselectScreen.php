<?php

namespace Tests\Fixtures\Edge;

class TabReselectScreen extends ChromeScreen
{
    public int $reselects = 0;

    public string $feed = '';

    public function backToTop(string $feed): void
    {
        $this->feed = $feed;
        $this->reselects++;
    }
}
