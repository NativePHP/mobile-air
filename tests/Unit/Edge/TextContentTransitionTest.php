<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Text;

/**
 * `content_transition` — animate in-place text changes. `numeric` is iOS's
 * `.contentTransition(.numericText(value:))` rolling-counter treatment;
 * Android approximates the roll with a directional slide + fade. The prop
 * rides the fallback (string-key) wire path, so no PropKey table change.
 */
it('exposes a contentTransition() fluent method', function () {
    $node = Text::make('42')->contentTransition('numeric')->toArray(new CallbackRegistry);

    expect($node['props']['content_transition'])->toBe('numeric');
});

it('exposes numericTransition() as sugar for the numeric kind', function () {
    $node = Text::make('42')->numericTransition()->toArray(new CallbackRegistry);

    expect($node['props']['content_transition'])->toBe('numeric');
});

it('maps the content-transition attribute in kebab and camel spellings', function () {
    $kebab = Text::make('42');
    $kebab->applyAttributes(['content-transition' => 'numeric']);
    expect($kebab->toArray(new CallbackRegistry)['props']['content_transition'])->toBe('numeric');

    $camel = Text::make('42');
    $camel->applyAttributes(['contentTransition' => 'opacity']);
    expect($camel->toArray(new CallbackRegistry)['props']['content_transition'])->toBe('opacity');
});

it('emits no content_transition prop by default', function () {
    $node = Text::make('42')->toArray(new CallbackRegistry);

    expect($node['props'])->not->toHaveKey('content_transition');
});
