<?php

// The web feature is layered along the axes a future package split cuts
// on: Renderer (tree → HTML) must stay consumable without Protocol (the
// stateless snapshot/HTTP machinery) or Bridge (browser drivers), so a
// desktop shell can reuse the renderer with its own transport and
// drivers. Protocol may depend on Renderer, never the other way.

arch('the renderer layer never depends on the protocol layer')
    ->expect('Native\Mobile\Edge\Web\Renderer')
    ->not->toUse('Native\Mobile\Edge\Web\Protocol');

arch('the renderer layer never depends on the bridge layer')
    ->expect('Native\Mobile\Edge\Web\Renderer')
    ->not->toUse('Native\Mobile\Edge\Web\Bridge');
