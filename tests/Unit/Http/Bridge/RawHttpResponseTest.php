<?php

use Illuminate\Http\Response as LaravelResponse;
use Native\Mobile\Http\Bridge\RawHttpResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Bridge;

beforeEach(function () {
    $this->files = [];
    $this->file = function (string $content): string {
        $path = tempnam(sys_get_temp_dir(), 'raw-response-');
        file_put_contents($path, $content);

        return $this->files[] = $path;
    };
});

afterEach(function () {
    foreach ($this->files as $path) {
        @unlink($path);
    }
});

it('writes a status line, the headers, a blank line and the exact body', function () {
    $body = "<p>one</p>\r\n\r\n<p>two</p>\n  ";
    $response = new Response($body, 201, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Custom' => 'a']);

    expect(RawHttpResponse::toString($response))->toBe(
        "HTTP/1.1 201 Created\r\n"
        ."content-type: text/html; charset=UTF-8\r\n"
        ."x-custom: a\r\n"
        ."cache-control: no-cache, private\r\n"
        .'date: '.$response->headers->get('date')."\r\n"
        .'content-length: '.strlen($body)."\r\n"
        ."\r\n"
        .$body
    );
});

it('keeps every byte of a binary body, NULs included', function () {
    $body = "\0".Bridge::allBytes()."\r\n\r\n".Bridge::allBytes()."\0";

    $parsed = Bridge::parse(RawHttpResponse::toString(new Response($body, 200, ['Content-Type' => 'image/png'])));

    expect($parsed['body'])->toBe($body)
        ->and($parsed['headers']['content-type'])->toBe(['image/png']);
});

it('replaces a content-length the app set and drops transfer-encoding', function () {
    $response = new Response('four', 200, ['Content-Length' => '999', 'Transfer-Encoding' => 'chunked']);

    $parsed = Bridge::parse(RawHttpResponse::toString($response));

    expect($parsed['headers']['content-length'])->toBe(['4'])
        ->and($parsed['headers'])->not->toHaveKey('transfer-encoding');
});

it('writes one set-cookie line per cookie', function () {
    $response = new Response('ok');
    $response->headers->setCookie(Cookie::create('first', 'one'));
    $response->headers->setCookie(Cookie::create('second', 'two'));

    $parsed = Bridge::parse(RawHttpResponse::toString($response));

    expect($parsed['headers']['set-cookie'])->toHaveCount(2)
        ->and($parsed['headers']['set-cookie'][0])->toStartWith('first=one;')
        ->and($parsed['headers']['set-cookie'][1])->toStartWith('second=two;');
});

it('leaves out a header that would break the framing, as header() refuses it', function () {
    $response = new Response('body');
    $response->headers->set('X-Split', "a\r\n\r\ninjected");
    $response->headers->set('X-Nul', "a\0b");
    $response->headers->set('X-Fine', 'still here');

    $parsed = Bridge::parse(RawHttpResponse::toString($response));

    expect($parsed['headers'])->not->toHaveKey('x-split')
        ->and($parsed['headers'])->not->toHaveKey('x-nul')
        ->and($parsed['headers']['x-fine'])->toBe(['still here'])
        ->and($parsed['body'])->toBe('body');
});

it('uses the same status text as the old eval, falling back to OK', function (int $code, string $text) {
    expect(Bridge::parse(RawHttpResponse::toString(new Response('', $code))))
        ->toMatchArray(['status' => $code, 'reason' => $text]);
})->with([
    [200, 'OK'],
    [302, 'Found'],
    [404, 'Not Found'],
    [418, 'I\'m a teapot'],
    [599, 'OK'],
]);

it('collects a streamed body, even when the callback flushes and leaves buffers open', function () {
    $level = ob_get_level();

    $response = new StreamedResponse(function () {
        echo "first\r\n\r\n";
        ob_flush();
        flush();
        echo "\0second";
        ob_start();
        echo ' left open';
    }, 200, ['Content-Type' => 'text/plain']);

    $parsed = Bridge::parse(RawHttpResponse::toString($response));

    expect($parsed['body'])->toBe("first\r\n\r\n\0second left open")
        ->and(ob_get_level())->toBe($level);
});

it('drops output the callback cleans away', function () {
    $response = new StreamedResponse(function () {
        echo 'discarded';
        ob_clean();
        echo 'kept';
    });

    expect(Bridge::parse(RawHttpResponse::toString($response))['body'])->toBe('kept');
});

it('restores the buffer level and rethrows when sendContent() throws', function () {
    $level = ob_get_level();

    $response = new StreamedResponse(function () {
        echo 'partial';
        ob_start();
        throw new RuntimeException('stream failed');
    });

    expect(fn () => RawHttpResponse::toString($response))->toThrow(RuntimeException::class, 'stream failed')
        ->and(ob_get_level())->toBe($level);
});

it('serves a BinaryFileResponse byte for byte with its real length', function () {
    $content = str_repeat(Bridge::allBytes(), 40)."\0";
    $response = new BinaryFileResponse(($this->file)($content), 200, ['Content-Type' => 'application/octet-stream']);
    $response->prepare(Request::create('/download'));

    $parsed = Bridge::parse(RawHttpResponse::toString($response));

    expect($parsed['body'])->toBe($content)
        ->and($parsed['headers']['content-length'])->toBe([(string) strlen($content)]);
});

it('serves the requested range of a BinaryFileResponse', function () {
    $response = new BinaryFileResponse(($this->file)('0123456789'));
    $request = Request::create('/download', server: ['HTTP_RANGE' => 'bytes=2-5']);
    $response->prepare($request);

    $parsed = Bridge::parse(RawHttpResponse::toString($response));

    expect($parsed['status'])->toBe(206)
        ->and($parsed['body'])->toBe('2345')
        ->and($parsed['headers']['content-range'])->toBe(['bytes 2-5/10']);
});

it('deletes a BinaryFileResponse file after sending when asked to', function () {
    $path = ($this->file)('gone after');
    $response = (new BinaryFileResponse($path))->deleteFileAfterSend();

    expect(Bridge::parse(RawHttpResponse::toString($response))['body'])->toBe('gone after')
        ->and($path)->not->toBeFile();
});

it('serves Laravel streamed downloads and plain responses', function () {
    $download = response()->streamDownload(fn () => print ("a\0b"), 'file.bin');
    $parsed = Bridge::parse(RawHttpResponse::toString($download));

    expect($parsed['body'])->toBe("a\0b")
        ->and($parsed['headers']['content-disposition'][0])->toContain('file.bin');

    expect(Bridge::parse(RawHttpResponse::toString(new LaravelResponse(['a' => 1])))['body'])->toBe('{"a":1}');
});

it('echoes exactly what toString() returns', function () {
    $response = new Response("x\0y\r\n\r\nz", 200, ['Content-Type' => 'application/octet-stream']);

    ob_start();
    RawHttpResponse::emit($response);
    $emitted = ob_get_clean();

    expect($emitted)->toBe(RawHttpResponse::toString($response));
});
