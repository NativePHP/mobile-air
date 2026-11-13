<?php

use Native\Mobile\Edge\Asgard\MediaUrl;
use Native\Mobile\Edge\Asgard\ServerChrome;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;

/**
 * Media URLs in what a server sends (docs/ASGARD-PROTOCOL.md §9.6). A
 * relative or root-relative src means an app asset or a device file in the
 * app's own views, so a fragment may only carry absolute https URLs.
 */
beforeEach(function () {
    NativeElementCollector::reset();
    ServerRegion::flush();
    NativeTagPrecompiler::setActive(true);
    config(['app.debug' => false]);

    if (! is_dir(__DIR__.'/views')) {
        mkdir(__DIR__.'/views', 0755, true);
    }
    app('view')->addLocation(__DIR__.'/views');
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
    NativeElementCollector::reset();
    ServerRegion::flush();

    foreach (glob(__DIR__.'/views/asgard-media-*.php') as $file) {
        unlink($file);
    }
});

function renderMediaView(string $name, string $template): array
{
    file_put_contents(__DIR__."/views/{$name}.blade.php", $template);

    ServerRegion::beginFrame();
    NativeElementCollector::reset();
    view($name)->render();

    return NativeElementCollector::collect()->toArray(new CallbackRegistry);
}

function imageFragment(mixed $src): array
{
    return ['type' => 'column', 'children' => [['type' => 'image', 'props' => ['src' => $src]]]];
}

it('accepts an absolute https image and passes it through untouched', function () {
    expect(ServerRegion::applyFragment('promo', imageFragment('https://example.test/storage/promo.jpg?v=2')))->toBeTrue();

    $tree = renderMediaView('asgard-media-https', <<<'BLADE'
        <native:column fill>
            @server('promo')
                <native:text>Loading…</native:text>
            @endserver
        </native:column>
        BLADE);

    expect($tree['children'][0]['children'][0]['children'][0]['props']['src'])
        ->toBe('https://example.test/storage/promo.jpg?v=2');
});

it('rejects a fragment whose media URL is not absolute https', function (mixed $src) {
    expect(ServerRegion::applyFragment('promo', imageFragment($src)))->toBeFalse();
    expect(ServerRegion::hasFragment('promo'))->toBeFalse();
})->with([
    'relative' => 'img/x.png',
    'dot relative' => './img/x.png',
    'root relative' => '/storage/promo.jpg',
    'protocol relative' => '//example.test/x.png',
    'http' => 'http://example.test/x.png',
    'file' => 'file:///var/mobile/x.png',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:image/png;base64,iVBORw0KGgo=',
    'content' => 'content://media/external/images/1',
    'no host' => 'https:///x.png',
    'whitespace' => 'https://example.test/a b.png',
    'not a string' => 42,
]);

it('says why with app.debug on', function () {
    config(['app.debug' => true]);

    expect(fn () => ServerRegion::applyFragment('promo', imageFragment('/storage/promo.jpg')))
        ->toThrow(RuntimeException::class, "'image' prop 'src' must be an absolute https:// or http:// URL");
});

it('allows http while app.debug is on', function () {
    config(['app.debug' => true]);

    expect(ServerRegion::applyFragment('promo', imageFragment('http://heimdall.test/img/heimdall.png')))->toBeTrue();
});

it('checks media URLs deep in a fragment', function () {
    $fragment = ['type' => 'column', 'children' => [['type' => 'row', 'children' => [imageFragment('img/x.png')]]]];

    expect(ServerRegion::applyFragment('promo', $fragment))->toBeFalse();
});

it('allows an empty src, which shows no image', function () {
    expect(ServerRegion::applyFragment('promo', imageFragment('')))->toBeTrue();
});

it('checks list item avatars and images and any src', function () {
    expect(MediaUrl::nodeProblem('list_item', ['leadingAvatar' => 'avatars/me.png']))->toContain("'leadingAvatar'");
    expect(MediaUrl::nodeProblem('list_item', ['leadingImage' => '/var/x.png']))->toContain("'leadingImage'");
    expect(MediaUrl::nodeProblem('list_item', ['leadingAvatar' => 'https://example.test/me.png']))->toBeNull();
    expect(MediaUrl::nodeProblem('webview', ['src' => '/settings']))->toContain("'src'");
    expect(MediaUrl::nodeProblem('some_plugin_video', ['src' => 'clip.mp4']))->toContain("'src'");
});

it('refuses a php-mode web view in a fragment', function () {
    expect(MediaUrl::nodeProblem('webview', ['src' => 'https://example.test', 'php' => true]))->toContain('php mode');
    expect(MediaUrl::nodeProblem('webview', ['src' => 'https://example.test', 'php' => 'true']))->toContain('php mode');
    expect(MediaUrl::nodeProblem('webview', ['src' => 'https://example.test', 'php' => false]))->toBeNull();
});

it('checks media URLs in server chrome', function () {
    expect(ServerChrome::parse(['nav_bar' => ['logo' => ['src' => 'img/logo.png']]], $reason))->toBeNull();
    expect($reason)->toContain('chrome.nav_bar.logo.src must be an absolute https:// URL');

    expect(ServerChrome::parse(['nav_bar' => ['logo' => ['src' => 'https://example.test/logo.png']]]))
        ->toBe(['nav_bar' => ['logo' => ['src' => 'https://example.test/logo.png', 'height' => 28.0]]]);

    expect(ServerChrome::parse(['nav_bar' => ['actions' => [['id' => 'a', 'image' => '/me.png']]]], $reason))->toBeNull();
    expect($reason)->toContain('chrome.nav_bar.actions[0].image');

    expect(ServerChrome::parse(['bottom_bar' => imageFragment('img/x.png')], $reason))->toBeNull();
    expect($reason)->toContain("'src'");

    config(['app.debug' => true]);
    expect(ServerChrome::parse(['nav_bar' => ['logo' => ['src' => 'http://heimdall.test/logo.png']]]))->not->toBeNull();
});

it('leaves app-authored images alone', function () {
    $tree = renderMediaView('asgard-media-app', <<<'BLADE'
        <native:column fill>
            <native:image src="img/x.png" />
            <native:image src="/var/mobile/photo.jpg" />
        </native:column>
        BLADE);

    expect($tree['children'][0]['props']['src'])->toBe('img/x.png');
    expect($tree['children'][1]['props']['src'])->toBe('/var/mobile/photo.jpg');
});
