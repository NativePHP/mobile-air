<?php

use Native\Mobile\Edge\NativeTagPrecompiler;

beforeEach(function () {
    $this->precompiler = new NativeTagPrecompiler;
    NativeTagPrecompiler::setActive(true);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
    NativeTagPrecompiler::resetElementEvents();
});

it('rewrites unknown @event attributes to _event- bindings', function () {
    $result = ($this->precompiler)('<native:order-row @order-shipped="markShipped(5)" />');

    expect($result)->toContain("'_event-order-shipped' => 'markShipped(5)'");
    expect($result)->not->toContain('@order-shipped');
});

it('leaves known press-family directives on their canonical attrs', function () {
    $result = ($this->precompiler)('<native:pressable @tap="save" @custom-thing="onThing" />');

    expect($result)->toContain("'_press' => 'save'");
    expect($result)->toContain("'_event-custom-thing' => 'onThing'");
});

it('does not touch blade directives without an equals sign', function () {
    $input = "@if (\$x)\n<native:spacer />\n@endif";
    $result = ($this->precompiler)($input);

    expect($result)->toContain('@if ($x)');
    expect($result)->toContain('@endif');
    expect($result)->not->toContain('_event-if');
});

it('supports blade interpolation in event binding expressions', function () {
    $result = ($this->precompiler)('<native:order-row @saved="markSaved({{ $id }})" />');

    expect($result)->toContain("'_event-saved' => 'markSaved(' . (\$id) . ')'");
});

it('rewrites a registered custom event to an underscored attr instead of _event-', function () {
    NativeTagPrecompiler::registerElementEvents(['link']);

    $result = ($this->precompiler)('<native:markdown @link="open" />');

    expect($result)->toContain("'_link' => 'open'");
    expect($result)->not->toContain('_event-link');
    expect($result)->not->toContain('@link');
});

it('rewrites every registered custom event name, not only link', function () {
    NativeTagPrecompiler::registerElementEvents(['link', 'scan']);

    $result = ($this->precompiler)('<native:scanner @link="open" @scan="onScan" />');

    expect($result)->toContain("'_link' => 'open'");
    expect($result)->toContain("'_scan' => 'onScan'");
    expect($result)->not->toContain('_event-link');
    expect($result)->not->toContain('_event-scan');
});

it('still rewrites unregistered names as child-component bindings', function () {
    NativeTagPrecompiler::registerElementEvents(['link']);

    $result = ($this->precompiler)('<native:order-row @link="open" @order-shipped="markShipped(5)" />');

    expect($result)->toContain("'_link' => 'open'");
    expect($result)->toContain("'_event-order-shipped' => 'markShipped(5)'");
});

it('rewrites a hyphenated registered name to an underscored attr', function () {
    NativeTagPrecompiler::registerElementEvents(['link-tapped']);

    $result = ($this->precompiler)('<native:markdown @link-tapped="open" />');

    expect($result)->toContain("'_link-tapped' => 'open'");
    expect($result)->not->toContain('_event-link-tapped');
});
