<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Http\Bridge\BridgeDispatcher;
use Native\Mobile\Runtime;
use Tests\Support\Bridge;

beforeEach(function () {
    $this->globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST];
    $this->errorLog = ini_get('error_log');
    $this->log = tempnam(sys_get_temp_dir(), 'bridge-log-');
    ini_set('error_log', $this->log);

    $this->tempDir = sys_get_temp_dir().'/bridge-dispatch-'.bin2hex(random_bytes(4));
    mkdir($this->tempDir);
    putenv('NATIVEPHP_TEMPDIR='.$this->tempDir);

    Runtime::boot($this->app);

    // What each route saw, for assertions made after the dispatch returns.
    $this->seen = new ArrayObject;
});

afterEach(function () {
    [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST] = $this->globals;
    ini_set('error_log', $this->errorLog);
    @unlink($this->log);
    putenv('NATIVEPHP_TEMPDIR');

    foreach (glob($this->tempDir.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->tempDir);

    BridgeDispatcher::$input = 'php://input';
    BridgeDispatcher::$bufferFloor = 0;
});

/** Call handle() the way the C bridge does: base64 arguments, the body in the input stream, the response echoed. */
function bridgeHandle(string $method, string $uri, string $body = '', string $contentType = '', string $headers = '', string $cookie = '', string $platform = 'ios', string $lane = 'persistent', string $script = '/app/vendor/nativephp/mobile/bootstrap/ios/native.php'): string
{
    BridgeDispatcher::$input = 'data://application/octet-stream;base64,'.base64_encode($body);

    ob_start();
    BridgeDispatcher::$bufferFloor = ob_get_level();

    BridgeDispatcher::handle(
        $platform,
        $lane,
        base64_encode($method),
        base64_encode($uri),
        base64_encode($script),
        base64_encode($cookie),
        base64_encode($contentType),
        base64_encode($headers),
    );

    return (string) ob_get_clean();
}

function bridgeJson(string $raw): array
{
    $response = Bridge::parse($raw);

    expect($response['status'])->toBe(200, $response['body']);

    return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
}

describe('handle()', function () {
    it('serves a multipart upload end to end from base64 arguments', function () {
        $bytes = Bridge::allBytes()."\r\n\r\n\0".Bridge::allBytes();

        Route::post('/upload', fn (Request $request) => [
            'title' => $request->input('title'),
            'tags' => $request->input('tags'),
            'class' => get_class($request->file('doc')),
            'valid' => $request->file('doc')->isValid(),
            'name' => $request->file('doc')->getClientOriginalName(),
            'mime' => $request->file('doc')->getClientMimeType(),
            'size' => $request->file('doc')->getSize(),
            'sha' => hash_file('sha256', $request->file('doc')->getPathname()),
            'content' => hash('sha256', $request->getContent()),
        ]);

        $body = Bridge::multipart([
            Bridge::field('title', 'Holiday'),
            Bridge::field('tags[]', 'sea'),
            Bridge::field('tags[]', 'sun'),
            Bridge::file('doc', 'photo.png', $bytes, 'image/png'),
        ]);

        $json = bridgeJson(bridgeHandle('POST', '/upload', $body, Bridge::contentType()));

        expect($json)->toBe([
            'title' => 'Holiday',
            'tags' => ['sea', 'sun'],
            'class' => UploadedFile::class,
            'valid' => true,
            'name' => 'photo.png',
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'sha' => hash('sha256', $bytes),
            'content' => hash('sha256', $body),
        ]);
    });

    it('passes a URI with a single quote, a backslash and %27 to Laravel unchanged', function () {
        Route::get('/echo/{rest}', fn (Request $request, string $rest) => [
            'server' => $request->server('REQUEST_URI'),
            'requestUri' => $request->getRequestUri(),
            'rest' => $rest,
            'q' => $request->query('q'),
            'b' => $request->query('b'),
            'script' => $request->server('SCRIPT_FILENAME'),
        ])->where('rest', '.*');

        $uri = "/echo/it's\\here%27?q=it's&b=back\\slash%27";
        $script = "/var/app's\\dir/native.php";

        $json = bridgeJson(bridgeHandle('GET', $uri, script: $script));

        expect($json)->toBe([
            'server' => $uri,
            'requestUri' => $uri,
            'rest' => "it's\\here'",
            'q' => "it's",
            'b' => "back\\slash'",
            'script' => $script,
        ]);
    });

    it('keeps a raw binary body byte for byte, both ways', function () {
        Route::post('/echo-body', fn (Request $request) => response($request->getContent(), 200, ['Content-Type' => 'application/octet-stream']));

        $body = "\0".Bridge::allBytes()."\r\n\r\n".Bridge::allBytes()."\0";

        $response = Bridge::parse(bridgeHandle('POST', '/echo-body', $body, 'application/octet-stream'));

        expect($response['body'])->toBe($body)
            ->and($response['headers']['content-type'])->toBe(['application/octet-stream']);
    });

    it('returns the old plain-text 500, now with a content-length, when an argument is not base64', function (string $lane, string $prefix) {
        BridgeDispatcher::$input = 'data://text/plain;base64,';
        ob_start();
        BridgeDispatcher::$bufferFloor = ob_get_level();
        BridgeDispatcher::handle('ios', $lane, base64_encode('GET'), 'not base64!', '', '', '', '');
        $response = Bridge::parse((string) ob_get_clean());

        expect($response['status'])->toBe(500)
            ->and($response['headers']['content-type'])->toBe(['text/plain'])
            ->and($response['body'])->toStartWith("{$prefix} dispatch error: Bridge argument [uri] is not valid base64.\n#0 ");
    })->with([
        ['persistent', 'Persistent'],
        ['webview', 'Webview'],
    ]);

    it('closes output buffers the previous dispatch left open', function () {
        Route::get('/clean', fn () => 'clean');

        ob_start();
        BridgeDispatcher::$bufferFloor = ob_get_level();
        ob_start();
        echo 'left over from last time';
        ob_start();

        BridgeDispatcher::$input = 'data://text/plain;base64,';
        BridgeDispatcher::handle('ios', 'persistent', base64_encode('GET'), base64_encode('/clean'), base64_encode('/native.php'), '', '', '');

        expect(ob_get_level())->toBe(BridgeDispatcher::$bufferFloor)
            ->and(Bridge::parse((string) ob_get_clean())['body'])->toBe('clean');
    });
});

describe('$_SERVER', function () {
    it('sets the same keys the old eval set', function (string $platform, string $lane, string $appUrl) {
        Route::get('/server', fn (Request $request) => collect($_SERVER)->only([
            'REQUEST_METHOD', 'REQUEST_URI', 'SCRIPT_FILENAME', 'PHP_SELF', 'HTTP_HOST', 'SERVER_NAME',
            'SERVER_PORT', 'APP_URL', 'NATIVEPHP_RUNNING', 'NATIVEPHP_PLATFORM', 'QUERY_STRING',
        ])->sortKeys()->all() + ['url' => $request->fullUrl()]);

        $json = bridgeJson(BridgeDispatcher::dispatch($platform, $lane, 'GET', '/server?a=1&b=two', '/app/native.php'));

        expect($json)->toBe(collect([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/server?a=1&b=two',
            'SCRIPT_FILENAME' => '/app/native.php',
            'PHP_SELF' => '/native.php',
            'HTTP_HOST' => '127.0.0.1',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => '80',
            'APP_URL' => $appUrl,
            'NATIVEPHP_RUNNING' => 'true',
            'NATIVEPHP_PLATFORM' => $platform,
            'QUERY_STRING' => 'a=1&b=two',
        ])->sortKeys()->all() + ['url' => 'http://127.0.0.1/server?a=1&b=two']);
    })->with([
        ['ios', 'persistent', 'php://127.0.0.1'],
        ['ios', 'webview', 'php://127.0.0.1'],
        ['android', 'persistent', 'http://127.0.0.1'],
        ['android', 'webview', 'http://127.0.0.1'],
    ]);

    it('turns the header block into HTTP_ keys and pins the host', function () {
        Route::get('/headers', fn (Request $request) => [
            'server' => collect($_SERVER)->filter(fn ($v, $k) => str_starts_with($k, 'HTTP_'))->sortKeys()->all(),
            'inertia' => $request->header('X-Inertia'),
        ]);

        $json = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'GET', '/headers', '/native.php', headers: implode("\r\n", [
            'X-Inertia: true',
            'Accept:  text/html ',
            'Host: elsewhere.test',
            'X-Multi: a',
            'x-multi: b',
            'Not a header',
            ': no name',
        ])));

        expect($json)->toBe([
            'server' => [
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_HOST' => '127.0.0.1',
                'HTTP_X_INERTIA' => 'true',
                'HTTP_X_MULTI' => 'a, b',
            ],
            'inertia' => 'true',
        ]);
    });

    it("never lets one request's headers leak into the next, whatever is in the environment", function (string $platform, string $lane) {
        Route::get('/leak', fn (Request $request) => [
            'inertia' => $request->header('X-Inertia'),
            'type' => $request->header('Content-Type'),
            'keys' => array_values(array_filter(array_keys($_SERVER), fn ($k) => str_starts_with($k, 'HTTP_X_') || str_starts_with($k, 'CONTENT_'))),
        ]);

        $first = bridgeJson(BridgeDispatcher::dispatch($platform, $lane, 'GET', '/leak', '/native.php', headers: "X-Inertia: true\r\nContent-Type: application/json"));

        // Android's Kotlin sets every header in the process env and never unsets it.
        putenv('HTTP_X_INERTIA=true');
        putenv('CONTENT_TYPE=application/json');

        try {
            $second = bridgeJson(BridgeDispatcher::dispatch($platform, $lane, 'GET', '/leak', '/native.php'));
        } finally {
            putenv('HTTP_X_INERTIA');
            putenv('CONTENT_TYPE');
        }

        expect($first)->toBe(['inertia' => 'true', 'type' => 'application/json', 'keys' => ['HTTP_X_INERTIA', 'CONTENT_TYPE']])
            ->and($second)->toBe(['inertia' => null, 'type' => null, 'keys' => []]);
    })->with([
        ['android', 'persistent'],
        ['ios', 'persistent'],
        ['android', 'webview'],
    ]);

    it('copies the rest of the environment on persistent lanes only', function () {
        Route::get('/env', fn () => ['value' => $_SERVER['BRIDGE_TEST_ENV'] ?? null]);
        putenv('BRIDGE_TEST_ENV=from-env');

        try {
            unset($_SERVER['BRIDGE_TEST_ENV']);
            $webview = bridgeJson(BridgeDispatcher::dispatch('ios', 'webview', 'GET', '/env', '/native.php'));
            $persistent = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'GET', '/env', '/native.php'));
        } finally {
            putenv('BRIDGE_TEST_ENV');
        }

        expect($webview['value'])->toBeNull()->and($persistent['value'])->toBe('from-env');
    });

    it('lets the cookie and content type arguments win over header copies', function () {
        Route::post('/wins', fn (Request $request) => [
            'cookies' => $_COOKIE,
            'type' => [$_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']],
            'x' => $request->input('x'),
        ]);

        $json = bridgeJson(BridgeDispatcher::dispatch(
            'android', 'persistent', 'POST', '/wins', '/native.php',
            cookie: 'a=from%20arg; b=2',
            contentType: 'application/x-www-form-urlencoded',
            headers: "Cookie: a=from-header\r\nContent-Type: text/plain",
            body: 'x=1',
        ));

        expect($json)->toBe([
            'cookies' => ['a' => 'from arg', 'b' => '2'],
            'type' => ['application/x-www-form-urlencoded', 'application/x-www-form-urlencoded'],
            'x' => '1',
        ]);
    });

    it('falls back to the header content type when no content type argument is given', function () {
        Route::post('/json', fn (Request $request) => ['a' => $request->input('a'), 'type' => $_SERVER['CONTENT_TYPE']]);

        $json = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'POST', '/json', '/native.php', headers: 'Content-Type: application/json', body: '{"a":[1,2]}'));

        expect($json)->toBe(['a' => [1, 2], 'type' => 'application/json']);
    });

    it('sets CONTENT_LENGTH to the real length of the body', function () {
        Route::match(['GET', 'POST'], '/length', fn () => [
            'length' => $_SERVER['CONTENT_LENGTH'] ?? null,
            'header' => $_SERVER['HTTP_CONTENT_LENGTH'] ?? null,
        ]);

        $post = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'POST', '/length', '/native.php', contentType: 'text/plain', headers: 'Content-Length: 999', body: "a\0b\r\n"));
        $get = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'GET', '/length', '/native.php'));

        expect($post)->toBe(['length' => '5', 'header' => '5'])
            ->and($get)->toBe(['length' => null, 'header' => null]);
    });

    it('parses cookies exactly as the old eval did', function () {
        Route::get('/cookies', fn (Request $request) => ['globals' => $_COOKIE, 'request' => $request->cookies->all()]);

        $json = bridgeJson(BridgeDispatcher::dispatch('ios', 'webview', 'GET', '/cookies', '/native.php', cookie: 'a=1; b=x%2By+z; flag; c==d'));

        expect($json['globals'])->toBe(['a' => '1', 'b' => 'x+y z', 'c' => '=d'])
            ->and($json['request'])->toBe($json['globals']);
    });

    it('fills $_REQUEST only for POST, PUT and PATCH with a body, as before', function () {
        Route::match(['GET', 'POST', 'DELETE'], '/request', fn () => $_REQUEST);

        $post = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'POST', '/request?q=1', '/native.php', cookie: 'c=3', contentType: 'application/x-www-form-urlencoded', body: 'p=2'));
        $get = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'GET', '/request?q=1', '/native.php'));
        $delete = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'DELETE', '/request?q=1', '/native.php', contentType: 'application/x-www-form-urlencoded', body: 'p=2'));

        expect($post)->toBe(['q' => '1', 'p' => '2', 'c' => '3'])
            ->and($get)->toBe([])
            ->and($delete)->toBe([]);
    });
});

describe('bodies', function () {
    it('gives the app urlencoded fields for POST, PUT, PATCH and DELETE', function (string $method) {
        Route::match([$method], '/form', fn (Request $request) => ['input' => $request->all(), 'post' => $_POST]);

        $json = bridgeJson(BridgeDispatcher::dispatch('android', 'webview', $method, '/form', '/native.php', contentType: 'application/x-www-form-urlencoded; charset=UTF-8', body: 'a=1&b[]=2&c=x+y'));

        expect($json['input'])->toBe(['a' => '1', 'b' => ['2'], 'c' => 'x y'])
            ->and($json['post'])->toBe($json['input']);
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);

    it('gives the app files and fields for multipart PUT, directly or through _method', function (string $method, array $extra) {
        Route::put('/files', fn (Request $request) => [
            'name' => $request->input('name'),
            'files' => array_map(fn (UploadedFile $file) => [$file->getClientOriginalName(), $file->getContent(), $file->isValid()], $request->file('many')),
            'empty' => $request->hasFile('empty'),
            'keys' => array_keys($request->allFiles()),
        ]);

        $body = Bridge::multipart([
            ...$extra,
            Bridge::field('name', 'Ada'),
            Bridge::file('many[]', 'one.txt', "one\0"),
            Bridge::file('many[]', 'two.txt', "two\r\n\r\n"),
            Bridge::file('empty', '', ''),
        ]);

        $json = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', $method, '/files', '/native.php', contentType: Bridge::contentType(), body: $body));

        expect($json)->toBe([
            'name' => 'Ada',
            'files' => [['one.txt', "one\0", true], ['two.txt', "two\r\n\r\n", true]],
            'empty' => false,
            'keys' => ['many'],
        ]);
    })->with([
        'PUT' => ['PUT', []],
        'POST with _method' => ['POST', [Bridge::field('_method', 'PUT')]],
    ]);

    it('lets the app move an upload, and deletes the ones it left once the response is built', function () {
        Route::post('/keep', function (Request $request) {
            $this->seen['kept'] = $request->file('keep')->move($this->tempDir, 'kept.bin')->getPathname();
            $this->seen['left'] = $request->file('leave')->getPathname();

            return ['exists' => is_file($this->seen['left'])];
        });

        $json = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'POST', '/keep', '/native.php', contentType: Bridge::contentType(), body: Bridge::multipart([
            Bridge::file('keep', 'k.bin', 'kept bytes'),
            Bridge::file('leave', 'l.bin', 'left bytes'),
        ])));

        expect($json['exists'])->toBeTrue()
            ->and(dirname($this->seen['left']))->toBe(realpath($this->tempDir))
            ->and($this->seen['left'])->not->toBeFile()
            ->and(file_get_contents($this->seen['kept']))->toBe('kept bytes');
    });

    it('deletes uploads even when the app throws', function () {
        Route::post('/boom', function (Request $request) {
            $this->seen['path'] = $request->file('f')->getPathname();

            throw new RuntimeException('boom');
        });

        $response = Bridge::parse(BridgeDispatcher::dispatch('ios', 'persistent', 'POST', '/boom', '/native.php', contentType: Bridge::contentType(), body: Bridge::multipart([
            Bridge::file('f', 'f.bin', 'x'),
        ])));

        expect($response['status'])->toBe(500)
            ->and($this->seen['path'])->not->toBeFile();
    });

    it('logs the warnings PHP would have logged while reading the body', function () {
        Route::post('/warn', fn (Request $request) => ['input' => $request->all()]);

        $json = bridgeJson(BridgeDispatcher::dispatch('ios', 'persistent', 'POST', '/warn', '/native.php', contentType: 'multipart/form-data', body: 'whatever'));

        expect($json['input'])->toBe([])
            ->and(file_get_contents($this->log))->toContain('PHP Warning:  Missing boundary in multipart/form-data POST data');
    });
});

describe('responses', function () {
    it('keeps output echoed outside the response at the start of the body', function () {
        Route::get('/stray', function () {
            echo 'stray ';

            return 'body';
        });

        expect(Bridge::parse(BridgeDispatcher::dispatch('ios', 'persistent', 'GET', '/stray', '/native.php'))['body'])->toBe('stray body');
    });

    it('serves files and streamed responses byte for byte', function () {
        $path = $this->tempDir.'/served.bin';
        file_put_contents($path, $bytes = str_repeat(Bridge::allBytes(), 64));

        Route::get('/file', fn () => response()->file($path, ['Content-Type' => 'application/octet-stream']));
        Route::get('/stream', fn () => response()->stream(function () {
            echo "chunk one\0";
            flush();
            echo "\r\n\r\nchunk two";
        }, 200, ['Content-Type' => 'text/plain']));

        $file = Bridge::parse(BridgeDispatcher::dispatch('ios', 'webview', 'GET', '/file', '/native.php'));
        $stream = Bridge::parse(BridgeDispatcher::dispatch('android', 'persistent', 'GET', '/stream', '/native.php'));

        expect($file['body'])->toBe($bytes)
            ->and($stream['body'])->toBe("chunk one\0\r\n\r\nchunk two");
    });

    it('rejects an unknown platform or lane with the old 500 text', function (string $platform, string $lane, string $message) {
        $response = Bridge::parse(BridgeDispatcher::dispatch($platform, $lane, 'GET', '/', '/native.php'));

        expect($response['status'])->toBe(500)
            ->and($response['body'])->toStartWith($message);
    })->with([
        ['windows', 'persistent', 'Persistent dispatch error: Unknown bridge platform [windows].'],
        ['ios', 'sideways', 'Persistent dispatch error: Unknown bridge lane [sideways].'],
    ]);
});

/*
 * The golden tests run the PHP that main's C bridge evals today (copied into
 * tests/Fixtures/Bridge/legacy-eval from mobile-air e85d8f1) next to
 * BridgeDispatcher, and require the same response apart from the new
 * content-length and the date.
 */
describe('against the old eval', function () {
    function legacyEval(string $file, array $args, string $body): string
    {
        $code = file_get_contents(dirname(__DIR__, 3).'/Fixtures/Bridge/legacy-eval/'.$file.'.txt');

        ob_start();
        $floor = ob_get_level();

        // Two test-only shims: keep PHPUnit's buffer, and read the body from a
        // data: URL because php://input is empty under the CLI.
        $code = str_replace('while (ob_get_level() > 0) { ob_end_clean(); }', "while (ob_get_level() > {$floor}) { ob_end_clean(); }", $code);
        $code = str_replace("file_get_contents('php://input')", "file_get_contents('data://application/octet-stream;base64,".base64_encode($body)."')", $code);

        eval(vsprintf($code, $args));

        return (string) ob_get_clean();
    }

    function comparable(string $raw): array
    {
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $lines = array_values(array_filter(explode("\r\n", $head), fn ($line) => ! preg_match('/^(date|content-length):/i', $line)));

        return ['head' => $lines, 'body' => $body];
    }

    beforeEach(function () {
        Route::match(['GET', 'POST'], '/golden/{page}', fn (Request $request, string $page) => match ($page) {
            'html' => response("<html>\r\n\r\n<body>two blank lines</body>\n  \n", 200, ['X-Golden' => 'yes']),
            'redirect' => redirect('/golden/html')->withCookie(cookie('flavour', 'oat')),
            'missing' => response('nope', 404),
            default => response()->json([
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'query' => $request->query(),
                'input' => $request->all(),
                'post' => $_POST,
                'request' => $_REQUEST,
                'cookies' => $_COOKIE,
                'golden' => $request->header('X-Golden'),
                'type' => $request->header('Content-Type'),
                'server' => collect($_SERVER)->only([
                    'REQUEST_METHOD', 'REQUEST_URI', 'SCRIPT_FILENAME', 'PHP_SELF', 'HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT',
                    'APP_URL', 'NATIVEPHP_RUNNING', 'NATIVEPHP_PLATFORM', 'QUERY_STRING', 'CONTENT_TYPE', 'HTTP_CONTENT_TYPE',
                    'HTTP_X_GOLDEN', 'HTTP_COOKIE',
                ])->sortKeys()->all(),
            ]),
        });
    });

    it('gives the same response on every lane', function (string $platform, string $lane, string $method, string $uri, string $contentType, string $body) {
        $script = "/app/vendor/nativephp/mobile/bootstrap/{$platform}/native.php";
        $cookie = 'session=abc%3D; theme=dark';

        if ($lane === 'webview') {
            $escape = fn (string $value) => addcslashes($value, "\\'");
            $legacy = legacyEval("{$platform}-webview", [
                $escape($method), $escape($uri), $escape($script),
                $escape($cookie), $escape($cookie),
                $escape($contentType), $escape($contentType), $escape($contentType),
            ], $body);
            $headers = '';
        } else {
            // The persistent lanes took headers, the cookie and the content
            // type from the process env, which Swift and Kotlin filled.
            $env = ['HTTP_X_GOLDEN' => 'yes', 'HTTP_COOKIE' => $cookie, 'NATIVEPHP_PLATFORM' => $platform];

            if ($contentType !== '') {
                $env['HTTP_CONTENT_TYPE'] = $contentType;
            }

            foreach ($env as $key => $value) {
                putenv("{$key}={$value}");
            }

            try {
                $legacy = legacyEval("{$platform}-persistent", [$method, $uri, $script], $body);
            } finally {
                foreach ($env as $key => $value) {
                    putenv($key);
                }
            }

            $headers = 'X-Golden: yes';
            putenv("NATIVEPHP_PLATFORM={$platform}");
        }

        try {
            $new = BridgeDispatcher::dispatch($platform, $lane, $method, $uri, $script, $cookie, $contentType, $headers, $body);
        } finally {
            putenv('NATIVEPHP_PLATFORM');
        }

        Bridge::parse($new);

        expect(comparable($new))->toBe(comparable($legacy));
    })->with([
        ['ios', 'persistent'],
        ['ios', 'webview'],
        ['android', 'persistent'],
        ['android', 'webview'],
    ])->with([
        'html' => ['GET', '/golden/html', '', ''],
        'json with a query' => ['GET', '/golden/json?x=1&y[]=2&z=a+b', '', ''],
        'urlencoded form' => ['POST', '/golden/json?from=query', 'application/x-www-form-urlencoded', 'a=1&b[]=2&c=x+y&d=%26'],
        'redirect with a cookie' => ['GET', '/golden/redirect', '', ''],
        'not found' => ['GET', '/golden/missing', '', ''],
    ]);
});
