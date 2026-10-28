<?php

use Native\Mobile\Microphone;
use Native\Mobile\Testing\FakeBridge;

afterEach(fn () => FakeBridge::disable());

it('records AAC with no limit unless asked otherwise', function () {
    $bridge = FakeBridge::enable();

    (new Microphone)->record()->id('memo')->start();

    expect(array_keys($bridge->callsTo('Microphone.Start')[0]['params']))->toEqualCanonicalizing(['id', 'event']);
});

it('records 16 kHz WAV with a limit when asked', function () {
    $bridge = FakeBridge::enable();

    (new Microphone)->record()->wav()->maxDuration(60)->start();

    expect($bridge->callsTo('Microphone.Start')[0]['params'])
        ->toMatchArray(['format' => 'wav', 'sampleRate' => 16000, 'maxDuration' => 60]);
});

it('takes another sample rate for WAV', function () {
    $bridge = FakeBridge::enable();

    (new Microphone)->record()->wav(44100)->start();

    expect($bridge->callsTo('Microphone.Start')[0]['params'])->toMatchArray(['format' => 'wav', 'sampleRate' => 44100]);
});

it('refuses a sample rate or a limit that cannot be recorded', function (Closure $build) {
    $build((new Microphone)->record());
})->with([
    'a sample rate too low' => [fn ($pending) => $pending->wav(4000)],
    'a sample rate too high' => [fn ($pending) => $pending->wav(96000)],
    'no time at all' => [fn ($pending) => $pending->maxDuration(0)],
])->throws(InvalidArgumentException::class);

it('reads how loud the microphone has been', function (mixed $answer, float $level) {
    $bridge = FakeBridge::enable()->respondTo('Microphone.GetLevel', $answer);

    expect((new Microphone)->level())->toBe($level)
        ->and($bridge->callsTo('Microphone.GetLevel'))->toHaveCount(1);
})->with([
    'a level' => [['level' => 0.42], 0.42],
    'past the loudest' => [['level' => 1.5], 1.0],
    'an older plugin with no levels' => [null, 0.0],
    'not a number' => [['level' => 'loud'], 0.0],
]);
