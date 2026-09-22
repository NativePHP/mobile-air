<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Native\Mobile\Http\Bridge\BridgeDispatcher;
use Native\Mobile\Support\Ios\Request as IosRequest;
use Symfony\Component\Process\Process;
use Tests\Support\Bridge;

/*
 * Classic mode runs bootstrap/{ios,android}/native.php once per request in a
 * fresh interpreter, with $_SERVER filled from the environment the native side
 * set and the body in php://input.
 */

beforeEach(function () {
    $this->globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST];
    $this->tempDir = sys_get_temp_dir().'/bridge-classic-'.bin2hex(random_bytes(4));
    mkdir($this->tempDir);
});

afterEach(function () {
    [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST] = $this->globals;
    BridgeDispatcher::$input = 'php://input';
    putenv('NATIVEPHP_TEMPDIR');

    foreach (glob($this->tempDir.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->tempDir);
});

describe('classicRequest()', function () {
    it('parses the body into $_POST, $_FILES and the request', function () {
        putenv('NATIVEPHP_TEMPDIR='.$this->tempDir);
        $bytes = Bridge::allBytes()."\r\n\r\n\0";
        $body = Bridge::multipart([Bridge::field('title', 'Hi'), Bridge::file('doc', 'a.bin', $bytes), Bridge::file('empty', '', '')]);

        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload?q=1',
            'QUERY_STRING' => 'q=1',
            'HTTP_CONTENT_TYPE' => Bridge::contentType(),
            'HTTP_COOKIE' => 'a=1; b=x%2By',
            'HTTP_HOST' => '127.0.0.1',
        ];

        [$request, $parsed] = BridgeDispatcher::classicRequest(body: $body);

        try {
            expect($request)->toBeInstanceOf(Request::class)
                ->and($request->input())->toBe(['title' => 'Hi', 'q' => '1'])
                ->and($request->query('q'))->toBe('1')
                ->and($request->cookies->all())->toBe(['a' => '1', 'b' => 'x+y'])
                ->and($request->file('doc'))->toBeInstanceOf(UploadedFile::class)
                ->and($request->file('doc')->isValid())->toBeTrue()
                ->and($request->file('doc')->getContent())->toBe($bytes)
                ->and(dirname($request->file('doc')->getPathname()))->toBe(realpath($this->tempDir))
                ->and($request->hasFile('empty'))->toBeFalse()
                ->and($request->getContent())->toBe($body)
                ->and($_POST)->toBe(['title' => 'Hi'])
                ->and(array_keys($_FILES))->toBe(['doc', 'empty'])
                ->and($_SERVER['CONTENT_TYPE'])->toBe(Bridge::contentType())
                ->and($_SERVER['CONTENT_LENGTH'])->toBe((string) strlen($body));

            $path = $request->file('doc')->getPathname();
        } finally {
            $parsed->cleanup();
        }

        expect($path)->not->toBeFile();
    });

    it('reads the body from php://input and builds the class it is given', function () {
        BridgeDispatcher::$input = 'data://application/octet-stream;base64,'.base64_encode('a=1&b[]=2');
        $_SERVER = ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/x?y=z', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];

        [$request] = BridgeDispatcher::classicRequest(IosRequest::class);

        expect($request)->toBeInstanceOf(IosRequest::class)
            ->and($request->getSchemeAndHttpHost())->toBe('php://127.0.0.1')
            ->and($request->method())->toBe('PUT')
            ->and($request->input())->toBe(['a' => '1', 'b' => ['2'], 'y' => 'z'])
            ->and($_SERVER['QUERY_STRING'])->toBe('y=z');
    });

    it('leaves the rest of $_SERVER as the native side set it', function () {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'APP_URL' => 'http://127.0.0.1', 'CUSTOM' => 'kept'];

        BridgeDispatcher::classicRequest(body: '');

        expect($_SERVER)->toBe(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'APP_URL' => 'http://127.0.0.1', 'CUSTOM' => 'kept', 'QUERY_STRING' => '']);
    });
});

/**
 * Run a classic-mode bootstrap in its own PHP process, as the native side does:
 * request values in the environment, the body where php://input would be.
 *
 * @param  array<string, string>  $env
 * @return array{head: string, body: string, stderr: string}
 */
function runClassic(string $platform, array $env, string $body, string $tempDir): array
{
    $root = dirname(__DIR__, 4);
    $work = $tempDir.'/run-'.bin2hex(random_bytes(3));
    mkdir($work);
    file_put_contents($work.'/body', $body);

    // Point handle()'s input at the body file, since the CLI has no request body.
    file_put_contents($work.'/prepend.php', '<?php require '.var_export($root.'/vendor/autoload.php', true).";\n"
        .'Native\Mobile\Http\Bridge\BridgeDispatcher::$input = getenv("BRIDGE_TEST_BODY_FILE");'."\n");

    // What the native side sets in the environment for every classic request:
    // run_php_request_len() in php_bridge.c, NativePHPApp.laravel() in Swift.
    $env += ['PHP_SELF' => '/native.php', 'HTTP_HOST' => '127.0.0.1', 'NATIVEPHP_RUNNING' => 'true'];

    if ($platform === 'android') {
        $script = $root.'/bootstrap/android/native.php';
        $env += [
            'APP_URL' => 'http://127.0.0.1',
            'COMPOSER_AUTOLOADER_PATH' => $root.'/vendor/autoload.php',
            'LARAVEL_BOOTSTRAP_PATH' => $root.'/tests/Fixtures/Bridge/classic-app/bootstrap',
        ];
    } else {
        // iOS finds the app relative to vendor/nativephp/mobile/bootstrap/ios.
        $script = $work.'/app/vendor/nativephp/mobile/bootstrap/ios/native.php';
        $env += ['APP_URL' => 'php://127.0.0.1', 'REMOTE_ADDR' => '0.0.0.0'];
        mkdir(dirname($script), 0777, true);
        mkdir($work.'/app/bootstrap');
        copy($root.'/bootstrap/ios/native.php', $script);
        file_put_contents($work.'/app/vendor/autoload.php', '<?php return require '.var_export($root.'/vendor/autoload.php', true).';');
        file_put_contents($work.'/app/bootstrap/app.php', '<?php return require '.var_export($root.'/tests/Fixtures/Bridge/classic-app/bootstrap/app.php', true).';');
    }

    $process = new Process(
        [PHP_BINARY, '-d', 'auto_prepend_file='.$work.'/prepend.php', $script],
        null,
        $env + ['BRIDGE_TEST_BODY_FILE' => $work.'/body', 'NATIVEPHP_TEMPDIR' => $tempDir],
    );
    $process->mustRun();

    (new Filesystem)->deleteDirectory($work);

    [$head, $responseBody] = explode("\r\n\r\n", $process->getOutput(), 2) + [1 => ''];

    return ['head' => $head, 'body' => $responseBody, 'stderr' => $process->getErrorOutput()];
}

describe('bootstrap native.php', function () {
    it('gives the app uploads and fields, then deletes the temp files', function (string $platform, array $env) {
        $bytes = Bridge::allBytes()."\0\r\n\r\n".Bridge::allBytes();
        $body = Bridge::multipart([Bridge::field('title', 'Hi'), Bridge::file('doc', 'photo.png', $bytes, 'image/png'), Bridge::file('empty', '', '')]);

        $run = runClassic($platform, $env + ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/inspect?q=1', 'QUERY_STRING' => 'q=1'], $body, $this->tempDir);
        $json = json_decode($run['body'], true);

        expect($run['head'])->toStartWith("HTTP/1.1 200 OK\r\nX-PHP-Timing: ")
            ->and($json)->toBeArray($run['body'].$run['stderr'])
            ->and($json['method'])->toBe('POST')
            ->and($json['query'])->toBe(['q' => '1'])
            ->and($json['input'])->toBe(['title' => 'Hi', 'q' => '1'])
            ->and($json['post'])->toBe(['title' => 'Hi'])
            ->and($json['phpFiles'])->toBe(['doc', 'empty'])
            ->and($json['contentLength'])->toBe((string) strlen($body))
            ->and($json['contentSha'])->toBe(hash('sha256', $body))
            ->and(array_keys($json['files']))->toBe(['doc'])
            ->and($json['files']['doc'])->toMatchArray([
                'class' => UploadedFile::class,
                'name' => 'photo.png',
                'type' => 'image/png',
                'size' => strlen($bytes),
                'sha' => hash('sha256', $bytes),
                'valid' => true,
            ])
            ->and($json['files']['doc']['path'])->not->toBeFile();
    })->with([
        'android' => ['android', ['CONTENT_TYPE' => Bridge::contentType(), 'HTTP_CONTENT_TYPE' => Bridge::contentType()]],
        'ios' => ['ios', ['HTTP_CONTENT_TYPE' => Bridge::contentType()]],
    ]);

    it('parses urlencoded PUT bodies, the query string and cookies', function (string $platform, array $env, string $class, string $schemeAndHost) {
        $run = runClassic($platform, $env + [
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/inspect?page=2',
            'QUERY_STRING' => 'page=2',
            'HTTP_COOKIE' => 'theme=dark; token=a%2Bb',
        ], 'name=Ada&tags[]=x', $this->tempDir);
        $json = json_decode($run['body'], true);

        expect($json)->toBeArray($run['body'].$run['stderr'])
            ->and($json)->toMatchArray([
                'class' => $class,
                'method' => 'PUT',
                'schemeAndHost' => $schemeAndHost,
                'query' => ['page' => '2'],
                'input' => ['name' => 'Ada', 'tags' => ['x'], 'page' => '2'],
                'cookies' => ['theme' => 'dark', 'token' => 'a+b'],
            ]);
    })->with([
        'android' => ['android', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], Request::class, 'http://127.0.0.1'],
        'ios' => ['ios', ['HTTP_CONTENT_TYPE' => 'application/x-www-form-urlencoded'], IosRequest::class, 'php://127.0.0.1'],
    ]);
});
