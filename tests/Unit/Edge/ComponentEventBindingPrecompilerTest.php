<?php

use Native\Mobile\Edge\NativeTagPrecompiler;

beforeEach(function () {
    $this->precompiler = new NativeTagPrecompiler;
    NativeTagPrecompiler::setActive(true);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
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
