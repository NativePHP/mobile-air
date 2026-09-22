<?php

use Native\Mobile\Http\Bridge\MultipartParser;
use Tests\Support\Bridge;

function multipartParts(string $body, string $boundary = Bridge::BOUNDARY): array
{
    return iterator_to_array((new MultipartParser)->parts($body, $boundary), false);
}

describe('boundary', function () {
    it('reads the boundary the way PHP does', function (string $contentType, ?string $expected) {
        expect(MultipartParser::boundary($contentType))->toBe($expected);
    })->with([
        'plain' => ['multipart/form-data; boundary=abc123', 'abc123'],
        'quoted' => ['multipart/form-data; boundary="a b;c"', 'a b;c'],
        'other parameters after' => ['multipart/form-data; boundary=abc; charset=utf-8', 'abc'],
        'other parameters before' => ['multipart/form-data; charset=utf-8; boundary=abc', 'abc'],
        'comma ends it' => ['multipart/form-data; boundary=abc,def', 'abc'],
        'upper case name' => ['multipart/form-data; BOUNDARY=abc', 'abc'],
        'missing' => ['multipart/form-data', null],
        'no equals sign' => ['multipart/form-data; boundary', null],
        'unterminated quote' => ['multipart/form-data; boundary="abc', null],
        'at the length limit' => ['multipart/form-data; boundary='.str_repeat('b', 5116), str_repeat('b', 5116)],
        'too long' => ['multipart/form-data; boundary='.str_repeat('b', 5117), null],
    ]);
});

describe('parts', function () {
    it('yields fields and files with their headers', function () {
        $parts = multipartParts(Bridge::multipart([
            Bridge::field('title', 'Hello'),
            Bridge::file('upload', 'photo.png', 'PNGDATA', 'image/png; name=x'),
        ]));

        expect($parts)->toHaveCount(2)
            ->and($parts[0])->toMatchArray(['name' => 'title', 'filename' => null, 'type' => null, 'content' => 'Hello', 'complete' => true])
            ->and($parts[1])->toMatchArray(['name' => 'upload', 'filename' => 'photo.png', 'type' => 'image/png', 'content' => 'PNGDATA', 'complete' => true])
            ->and($parts[1]['headers'])->toBe([
                'content-disposition' => 'form-data; name="upload"; filename="photo.png"',
                'content-type' => 'image/png; name=x',
            ]);
    });

    it('keeps every byte of binary content, NULs and blank lines included', function () {
        $content = Bridge::allBytes()."\r\n\r\n\0\0--not-a-boundary\r\n".Bridge::allBytes();

        $parts = multipartParts(Bridge::multipart([Bridge::file('f', 'bin', $content)]));

        expect($parts[0]['content'])->toBe($content);
    });

    it('drops only one CR before the delimiter', function () {
        $parts = multipartParts("--B\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nvalue\r\r\n--B--", 'B');

        expect($parts[0]['content'])->toBe("value\r");
    });

    it('accepts LF-only line endings', function () {
        $parts = multipartParts("--B\nContent-Disposition: form-data; name=\"a\"\n\nvalue\n--B--\n", 'B');

        expect($parts[0])->toMatchArray(['name' => 'a', 'content' => 'value', 'complete' => true]);
    });

    it('ignores a preamble and an epilogue', function () {
        $parts = multipartParts("preamble\r\n--B\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n--B--\r\nepilogue", 'B');

        expect($parts)->toHaveCount(1)->and($parts[0]['content'])->toBe('1');
    });

    it('marks a part the body ends in the middle of as incomplete', function () {
        $parts = multipartParts("--B\r\nContent-Disposition: form-data; name=\"a\"; filename=\"x\"\r\n\r\ncut short", 'B');

        expect($parts[0])->toMatchArray(['content' => 'cut short', 'complete' => false]);
    });

    it('stops in front of a partial delimiter at the very end', function () {
        $parts = multipartParts("--Boundary\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nvalue\r\n--Bou", 'Boundary');

        expect($parts[0])->toMatchArray(['content' => 'value', 'complete' => false]);
    });

    it('matches header names case-insensitively and keeps the first', function () {
        $parts = multipartParts("--B\r\ncontent-DISPOSITION: form-data; name=\"first\"\r\nContent-Disposition: form-data; name=\"second\"\r\n\r\nv\r\n--B--", 'B');

        expect($parts[0]['name'])->toBe('first');
    });

    it('joins folded header lines', function () {
        $parts = multipartParts("--B\r\nContent-Disposition: form-data;\r\n name=\"folded\"\r\n\r\nv\r\n--B--", 'B');

        expect($parts[0]['name'])->toBe('folded');
    });

    it('reads quoted, single-quoted, escaped and bare parameters', function () {
        $parts = multipartParts(Bridge::multipart([
            "Content-Disposition: form-data; name=\"a;b\"; filename=\"q\\\"u'o;te.txt\"\r\n\r\n1",
            "Content-Disposition: form-data; name='single'\r\n\r\n2",
            "content-disposition:form-data;name=bare;FILENAME=bare.txt\r\n\r\n3",
            "Content-Disposition: form-data; name=\"back\\\\slash\"\r\n\r\n4",
        ]));

        expect(array_column($parts, 'name'))->toBe(['a;b', 'single', 'bare', 'back\\slash'])
            ->and(array_column($parts, 'filename'))->toBe(['q"u\'o;te.txt', null, 'bare.txt', null]);
    });

    it('tells an empty filename from a missing one', function () {
        $parts = multipartParts(Bridge::multipart([
            Bridge::file('empty', '', ''),
            Bridge::field('none', 'x'),
        ]));

        expect($parts[0]['filename'])->toBe('')->and($parts[1]['filename'])->toBeNull();
    });

    it('cuts header values at a NUL, as C does', function () {
        $parts = multipartParts(Bridge::multipart(["Content-Disposition: form-data; name=\"a\0b\"\r\n\r\nv"]));

        expect($parts[0]['name'])->toBe('a');
    });

    it('yields a part without Content-Disposition with no name', function () {
        $parts = multipartParts(Bridge::multipart(["Content-Type: text/plain\r\n\r\nv"]));

        expect($parts[0])->toMatchArray(['name' => null, 'filename' => null, 'type' => 'text/plain', 'content' => 'v'])
            ->and($parts[0]['headers'])->not->toHaveKey('content-disposition');
    });

    it('yields nothing when the delimiter never appears', function () {
        expect(multipartParts("no multipart here\r\n", 'B'))->toBe([]);
    });
});
