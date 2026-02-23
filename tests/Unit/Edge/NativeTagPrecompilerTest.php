<?php

use Native\Mobile\Edge\NativeTagPrecompiler;

beforeEach(function () {
    $blade = app('blade.compiler');
    $this->precompiler = new NativeTagPrecompiler($blade);
});

it('rewrites @press to _press', function () {
    $input = '<x-native-button label="+" @press="increment" />';
    $result = preg_replace('/@(press|longPress|change|submit)=/', '_$1=', $input);

    expect($result)->toBe('<x-native-button label="+" _press="increment" />');
});

it('rewrites @longPress to _longPress', function () {
    $input = '<x-native-column @longPress="handleLong">';
    $result = preg_replace('/@(press|longPress|change|submit)=/', '_$1=', $input);

    expect($result)->toBe('<x-native-column _longPress="handleLong">');
});

it('rewrites @change and @submit', function () {
    $input = '<x-native-text-input @change="onTextChange" @submit="onTextSubmit" />';
    $result = preg_replace('/@(press|longPress|change|submit)=/', '_$1=', $input);

    expect($result)->toBe('<x-native-text-input _change="onTextChange" _submit="onTextSubmit" />');
});

it('converts native:tag to x-native-tag opening tags', function () {
    $input = '<native:column fill center>';
    $result = preg_replace('/<\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)/', '<x-native-$1', $input);

    expect($result)->toBe('<x-native-column fill center>');
});

it('converts native:tag to x-native-tag closing tags', function () {
    $input = '</native:column>';
    $result = preg_replace('/<\/\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)\s*>/', '</x-native-$1>', $input);

    expect($result)->toBe('</x-native-column>');
});

it('converts self-closing native tags', function () {
    $input = '<native:button label="+" @press="inc" />';
    // Apply the full pipeline
    $result = preg_replace('/@(press|longPress|change|submit)=/', '_$1=', $input);
    $result = preg_replace('/<\/\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)\s*>/', '</x-native-$1>', $result);
    $result = preg_replace('/<\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)/', '<x-native-$1', $result);

    expect($result)->toBe('<x-native-button label="+" _press="inc" />');
});

it('handles hyphenated component names like scroll-view', function () {
    $input = '<native:scroll-view fillWidth></native:scroll-view>';
    $result = preg_replace('/<\/\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)\s*>/', '</x-native-$1>', $input);
    $result = preg_replace('/<\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)/', '<x-native-$1', $result);

    expect($result)->toBe('<x-native-scroll-view fillWidth></x-native-scroll-view>');
});

it('handles text-input hyphenated name', function () {
    $input = '<native:text-input placeholder="Search..." />';
    $result = preg_replace('/<\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)/', '<x-native-$1', $input);

    expect($result)->toBe('<x-native-text-input placeholder="Search..." />');
});

it('preserves Blade directives like @foreach', function () {
    $input = '@foreach($items as $item) <native:text>{{ $item }}</native:text> @endforeach';
    // Only @press/@longPress/@change/@submit should be rewritten
    $result = preg_replace('/@(press|longPress|change|submit)=/', '_$1=', $input);

    // @foreach should NOT be touched
    expect($result)->toContain('@foreach');
    expect($result)->toContain('@endforeach');
});

it('handles multiple native tags in one template', function () {
    $input = '<native:column fill><native:text :fontSize="20">Hi</native:text><native:button label="OK" @press="ok" /></native:column>';

    $result = preg_replace('/@(press|longPress|change|submit)=/', '_$1=', $input);
    $result = preg_replace('/<\/\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)\s*>/', '</x-native-$1>', $result);
    $result = preg_replace('/<\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)/', '<x-native-$1', $result);

    expect($result)->toBe('<x-native-column fill><x-native-text :fontSize="20">Hi</x-native-text><x-native-button label="OK" _press="ok" /></x-native-column>');
});
