<?php

use Native\Mobile\Support\AnimatedWebp;
use Tests\Support\AnimatedWebpFixture;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/nativephp_animated_webp_'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('reads the canvas size of an animated WebP', function () {
    AnimatedWebpFixture::write($this->dir.'/splash.webp', 1080, 1920);

    expect(AnimatedWebp::size($this->dir.'/splash.webp'))->toBe([1080, 1920]);
});

it('returns null for a still WebP, a PNG and a missing file', function () {
    $image = imagecreatetruecolor(400, 700);
    imagewebp($image, $this->dir.'/still.webp');
    imagepng($image, $this->dir.'/still.png');
    imagedestroy($image);

    expect(AnimatedWebp::size($this->dir.'/still.webp'))->toBeNull()
        ->and(AnimatedWebp::size($this->dir.'/still.png'))->toBeNull()
        ->and(AnimatedWebp::size($this->dir.'/missing.webp'))->toBeNull();
});
