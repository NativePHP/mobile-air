<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Native\Mobile\Http\Bridge\RequestBodyParser;
use Native\Mobile\Http\Bridge\RequestFactory;
use Symfony\Component\Process\Process;
use Tests\Support\Bridge;

describe('headerVariables', function () {
    it('names headers the way a SAPI does', function () {
        expect(RequestFactory::headerVariables(implode("\r\n", [
            'X-Inertia: true',
            'accept:  text/html ',
            'Content-Type: text/plain',
            'X-Multi: a',
            'x-multi: b',
            'Cookie: a=1',
            'Cookie: b=2',
            'Not a header',
            ': no name',
            '',
        ])))->toBe([
            'HTTP_X_INERTIA' => 'true',
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_CONTENT_TYPE' => 'text/plain',
            'HTTP_X_MULTI' => 'a, b',
            'HTTP_COOKIE' => 'a=1; b=2',
        ]);
    });

    it('accepts bare LF line endings and an empty block', function () {
        expect(RequestFactory::headerVariables("A: 1\nB: 2"))->toBe(['HTTP_A' => '1', 'HTTP_B' => '2'])
            ->and(RequestFactory::headerVariables(''))->toBe([]);
    });
});

describe('cookies', function () {
    it('splits a Cookie header into names and urldecoded values', function (string $header, array $expected) {
        expect(RequestFactory::cookies($header))->toBe($expected);
    })->with([
        'usual' => ['a=1; b=x%2By+z', ['a' => '1', 'b' => 'x+y z']],
        'no space after the semicolon' => ['a=1;b=2', ['a' => '1', 'b' => '2']],
        'equals signs in the value' => ['c==d; e=f=g', ['c' => '=d', 'e' => 'f=g']],
        'pairs without a value or a name' => ['flag; =orphan; ; a=1', ['a' => '1']],
        'the later one wins' => ['a=1; a=2', ['a' => '2']],
        'empty' => ['', []],
    ]);
});

describe('query', function () {
    it('parses a query string as PHP fills $_GET', function () {
        expect(RequestFactory::query('a=1&b[]=2&c.d=x+y&e=%00'))->toBe(['a' => '1', 'b' => ['2'], 'c_d' => 'x y', 'e' => "\0"])
            ->and(RequestFactory::query(''))->toBe([]);
    });

    it('collects warnings instead of raising them', function () {
        $script = <<<'PHP'
            require $argv[1];
            set_error_handler(fn () => throw new RuntimeException('raised'));
            $warnings = [];
            $query = Native\Mobile\Http\Bridge\RequestFactory::query('a=1&b=2&c=3', $warnings);
            echo json_encode([$query, $warnings]);
            PHP;

        $process = new Process([PHP_BINARY, '-d', 'max_input_vars=2', '-r', $script, dirname(__DIR__, 4).'/vendor/autoload.php']);
        $process->mustRun();

        [$query, $warnings] = json_decode($process->getOutput(), true);

        expect($query)->toBe(['a' => '1', 'b' => '2'])
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('Input variables exceeded 2');
    });
});

describe('make', function () {
    it('builds the request from its parts, uploads and method override included', function () {
        $parsed = (new RequestBodyParser)->parse('POST', Bridge::contentType(), $body = Bridge::multipart([
            Bridge::field('_method', 'PUT'),
            Bridge::field('name', 'Ada'),
            Bridge::file('doc', 'a.txt', "a\0b"),
            Bridge::file('empty', '', ''),
            Bridge::file('nested[inner]', '', ''),
        ]));

        try {
            $request = RequestFactory::make(
                ['q' => '1'],
                $parsed,
                ['session' => 'abc'],
                ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x?q=1', 'HTTP_HOST' => '127.0.0.1', 'CONTENT_TYPE' => Bridge::contentType()],
                $body,
            );

            expect($request)->toBeInstanceOf(Request::class)
                ->and($request->method())->toBe('PUT')
                ->and($request->query('q'))->toBe('1')
                ->and($request->input('name'))->toBe('Ada')
                ->and($request->cookie('session'))->toBe('abc')
                ->and($request->getContent())->toBe($body)
                ->and($request->file('doc'))->toBeInstanceOf(UploadedFile::class)
                ->and($request->file('doc')->isValid())->toBeTrue()
                ->and($request->file('doc')->getContent())->toBe("a\0b")
                ->and(array_keys($request->allFiles()))->toBe(['doc'])
                ->and($request->hasFile('empty'))->toBeFalse();
        } finally {
            $parsed->cleanup();
        }
    });

    it('decodes a JSON body into input, as Request::capture() does', function () {
        $request = RequestFactory::make([], (new RequestBodyParser)->parse('POST', 'application/json', '{"a":[1]}'), [], [
            'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json',
        ], '{"a":[1]}');

        expect($request->input('a'))->toBe([1]);
    });
});
