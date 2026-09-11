<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Image;
use Native\Mobile\Edge\ImageSource;

/**
 * A relative `src` means "an asset in `public/`". On device the native
 * renderer resolves that against the app's public directory, so the value
 * travels untouched; under Jump `public/` lives on the developer's machine
 * and only reaches the phone over HTTP, so it has to become a URL before it
 * goes over the wire.
 */
afterEach(function () {
    putenv('JUMP_BRIDGE_PORT');
});

function jumping(bool $active): void
{
    $active ? putenv('JUMP_BRIDGE_PORT=3002') : putenv('JUMP_BRIDGE_PORT');
}

it('leaves a relative path alone on device — the renderer resolves it', function () {
    jumping(false);

    expect(ImageSource::forDevice('img/logo.png'))->toBe('img/logo.png');
});

it('rewrites a relative path to a fetchable URL under Jump', function () {
    jumping(true);

    expect(ImageSource::forDevice('img/logo.png'))->toBe(asset('img/logo.png'));
});

it('drops a leading ./ before building the URL', function () {
    jumping(true);

    expect(ImageSource::forDevice('./img/logo.png'))->toBe(asset('img/logo.png'));
});

it('passes through values that already point somewhere real', function (string $src) {
    jumping(true);

    expect(ImageSource::forDevice($src))->toBe($src);
})->with([
    'empty' => '',
    // Absolute device path — a camera capture. Rewriting it would break it.
    'device file' => '/var/mobile/Containers/Data/Application/x/tmp/photo.jpg',
    'file url' => 'file:///var/mobile/photo.jpg',
    'remote' => 'https://example.com/logo.png',
    'data uri' => 'data:image/png;base64,iVBORw0KGgo=',
    'content uri' => 'content://media/external/images/media/42',
]);

it('applies to the image element, from both the tag and the fluent API', function () {
    jumping(true);

    $fromTag = new Image;
    $fromTag->applyAttributes(['src' => 'img/logo.png']);

    $tagSrc = $fromTag->toArray(new CallbackRegistry)['props']['src'];
    $fluentSrc = Image::make('img/logo.png')->toArray(new CallbackRegistry)['props']['src'];

    expect($tagSrc)->toBe(asset('img/logo.png'))
        ->and($fluentSrc)->toBe(asset('img/logo.png'));
});
