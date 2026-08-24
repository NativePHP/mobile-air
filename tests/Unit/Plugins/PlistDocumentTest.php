<?php

use Native\Mobile\Plugins\Compilers\PlistDocument;

/**
 * Structural plist merging. Plugins may declare any value Apple accepts,
 * and the base Info.plist already nests arrays of dicts, so the merge
 * has to be aware of structure rather than treat the file as text.
 */
function plist(string $dict = ''): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">'."\n"
        .'<plist version="1.0">'."\n<dict>{$dict}\n</dict>\n</plist>\n";
}

function basePlist(): string
{
    return file_get_contents(__DIR__.'/../../../resources/xcode/NativePHP/Info.plist');
}

it('renders every value type and reads it back', function () {
    $entries = [
        'AString' => 'text',
        'ABool' => false,
        'AnInt' => 42,
        'AReal' => 1.5,
        'AList' => ['a', 'b'],
        'ADict' => ['Inner' => true, 'Items' => [['Id' => 'x']]],
    ];

    $doc = PlistDocument::fromXml(plist());
    $doc->merge($entries);

    $xml = $doc->toXml();
    expect($xml)->toContain('<false/>', '<integer>42</integer>', '<real>1.5</real>');
    expect(PlistDocument::fromXml($xml)->all())->toBe($entries);
});

it('unions lists on content so rebuilds and second plugins never duplicate', function () {
    $item = ['SKAdNetworkIdentifier' => 'cstr6suwn9.skadnetwork'];
    $doc = PlistDocument::fromXml(plist());

    $doc->merge(['SKAdNetworkItems' => [$item]]);
    $doc->merge(['SKAdNetworkItems' => [$item]]);
    expect($doc->get('SKAdNetworkItems'))->toBe([$item]);

    $other = ['SKAdNetworkIdentifier' => '4fzdc2evr5.skadnetwork'];
    $doc->merge(['SKAdNetworkItems' => [$other]]);
    expect($doc->get('SKAdNetworkItems'))->toBe([$item, $other]);
});

it('merges dicts key by key', function () {
    $doc = PlistDocument::fromXml(basePlist());
    $doc->merge(['NSAppTransportSecurity' => ['NSAllowsArbitraryLoads' => true]]);

    expect($doc->get('NSAppTransportSecurity'))->toBe([
        'NSAllowsArbitraryLoadsInWebContent' => true,
        'NSAllowsArbitraryLoads' => true,
    ]);
});

it('replaces a value whose type changed', function () {
    // The old text-based merge left <string></string> for a false bool in
    // scaffolds that already exist, so the typed value must win on rebuild.
    $doc = PlistDocument::fromXml(plist('<key>FirebaseAppDelegateProxyEnabled</key><string></string>'));
    $doc->merge(['FirebaseAppDelegateProxyEnabled' => false]);

    expect($doc->get('FirebaseAppDelegateProxyEnabled'))->toBeFalse();
    expect(substr_count($doc->toXml(), 'FirebaseAppDelegateProxyEnabled'))->toBe(1);
});

it('appends array-of-dict items beside existing ones, never inside a nested array', function () {
    $doc = PlistDocument::fromXml(basePlist());
    $doc->merge(['CFBundleURLTypes' => [['CFBundleURLSchemes' => ['probe']]]]);

    $types = $doc->get('CFBundleURLTypes');
    expect($types)->toHaveCount(2);
    expect($types[0]['CFBundleURLSchemes'])->toBe(['nativephp']);
    expect($types[1])->toBe(['CFBundleURLSchemes' => ['probe']]);
});

it('only matches keys at the top level', function () {
    // CFBundleTypeRole exists inside CFBundleURLTypes in the base plist.
    $doc = PlistDocument::fromXml(basePlist());
    $doc->merge(['CFBundleTypeRole' => 'Editor']);

    expect($doc->get('CFBundleTypeRole'))->toBe('Editor');
    expect($doc->get('CFBundleURLTypes')[0]['CFBundleTypeRole'])->toBe('Viewer');
});

it('escapes markup in strings', function () {
    $doc = PlistDocument::fromXml(plist());
    $doc->merge(['NSCameraUsageDescription' => "Foto's & <video>"]);

    expect($doc->toXml())->toContain('&amp; &lt;video&gt;');
    expect(PlistDocument::fromXml($doc->toXml())->get('NSCameraUsageDescription'))->toBe("Foto's & <video>");
});

it('leaves untouched entries byte for byte', function () {
    $doc = PlistDocument::fromXml(basePlist());
    $doc->merge([]);

    expect($doc->toXml())->toBe(basePlist());
});

it('rejects input without a root dict', function () {
    PlistDocument::fromXml('<plist version="1.0"><array/></plist>');
})->throws(InvalidArgumentException::class);
