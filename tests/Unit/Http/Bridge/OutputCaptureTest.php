<?php

use Native\Mobile\Http\Bridge\OutputCapture;

it('returns the result and captures what was echoed', function () {
    $level = ob_get_level();

    $result = OutputCapture::run(function () {
        echo "a\0b\r\n\r\n";

        return 42;
    }, $output);

    expect($result)->toBe(42)
        ->and($output)->toBe("a\0b\r\n\r\n")
        ->and(ob_get_level())->toBe($level);
});

it('keeps flushed output and buffers left open, and drops cleaned output', function () {
    $level = ob_get_level();

    OutputCapture::run(function () {
        echo 'one ';
        ob_flush();
        flush();
        echo 'dropped';
        ob_clean();
        echo 'two';
        ob_start();
        echo ' three';
    }, $output);

    expect($output)->toBe('one two three')
        ->and(ob_get_level())->toBe($level);
});

it('puts the buffer level back and rethrows when the callback throws', function () {
    $level = ob_get_level();

    expect(fn () => OutputCapture::run(function () {
        echo 'partial';
        ob_start();

        throw new RuntimeException('failed');
    }))->toThrow(RuntimeException::class, 'failed')
        ->and(ob_get_level())->toBe($level);
});
