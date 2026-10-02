<?php

/*
 * Sends the same raw bodies to PHP's own request parser and to
 * RequestBodyParser, each in a `php -S` server started with the same limits,
 * and requires the same $_POST, the same $_FILES and the same warning.
 *
 * The built-in server never exposes a multipart body through php://input, so
 * the RequestBodyParser server gets the body under another content type and
 * the real one in X-Real-Content-Type.
 */

use Symfony\Component\Process\Process;
use Tests\Support\Bridge;

const PARITY_INI = [
    'upload_max_filesize' => '6000',
    'post_max_size' => '300000',
    'max_file_uploads' => '4',
    'max_input_vars' => '3',
    'max_input_nesting_level' => '4',
    'display_errors' => '0',
    'display_startup_errors' => '0',
    'log_errors' => '0',
];

function parityServer(string $name, string $script, array $extraIni = []): array
{
    $dir = sys_get_temp_dir().'/bridge-parity-'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/index.php', $script);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $command = [PHP_BINARY];

    foreach ([...PARITY_INI, ...$extraIni] as $key => $value) {
        array_push($command, '-d', "{$key}={$value}");
    }

    array_push($command, '-S', "127.0.0.1:{$port}", '-t', $dir);

    $process = new Process($command);
    $process->start();

    $deadline = microtime(true) + 5;

    while (microtime(true) < $deadline) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

        if ($connection) {
            fclose($connection);

            return ['process' => $process, 'port' => $port, 'dir' => $dir];
        }

        usleep(50_000);
    }

    $process->stop(0);

    return ['process' => null, 'port' => $port, 'dir' => $dir];
}

function parityPost(int $port, string $contentType, string $body, bool $disguise): array
{
    $headers = $disguise
        ? ['Content-Type: application/octet-stream', "X-Real-Content-Type: {$contentType}"]
        : ["Content-Type: {$contentType}"];

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [...$headers, 'Content-Length: '.strlen($body)]),
        'content' => $body,
        'ignore_errors' => true,
        'protocol_version' => 1.0,
        'timeout' => 10,
    ]]);

    $response = (string) file_get_contents("http://127.0.0.1:{$port}/", false, $context);

    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

$normalise = <<<'PHP'
    function normalise(array $files): array {
        foreach ($files as $key => $file) {
            if (! isset($file['tmp_name'])) { continue; }
            $tmp = $file['tmp_name'];
            $hash = fn ($path) => $path === '' ? '' : 'sha256:'.(is_file($path) ? hash_file('sha256', $path) : 'missing');
            if (is_array($tmp)) { array_walk_recursive($tmp, function (&$path) use ($hash) { $path = $hash($path); }); } else { $tmp = $hash($tmp); }
            $files[$key]['tmp_name'] = $tmp;
        }
        return $files;
    }
    PHP;

beforeAll(function () use ($normalise) {
    $GLOBALS['bridgeParity'] = [
        'php' => parityServer('php', "<?php\n{$normalise}\n".<<<'PHP'
            header('Content-Type: application/json');
            $warning = error_get_last()['message'] ?? null;
            echo json_encode([
                'fields' => $_POST,
                'files' => normalise($_FILES),
                'warning' => $warning === null ? null : preg_replace('/^PHP Request Startup: /', '', $warning),
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            PHP),
        'ours' => parityServer('ours', "<?php\nrequire ".var_export(dirname(__DIR__, 4).'/vendor/autoload.php', true).";\n{$normalise}\n".<<<'PHP'
            header('Content-Type: application/json');
            $parsed = (new Native\Mobile\Http\Bridge\RequestBodyParser)->parse(
                $_SERVER['REQUEST_METHOD'],
                $_SERVER['HTTP_X_REAL_CONTENT_TYPE'] ?? '',
                file_get_contents('php://input'),
            );
            $warnings = $parsed->warnings;
            echo json_encode([
                'fields' => $parsed->fields,
                'files' => normalise($parsed->phpFiles),
                'warning' => $warnings === [] ? null : end($warnings),
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            $parsed->cleanup();
            PHP, ['enable_post_data_reading' => '0']),
    ];
});

afterAll(function () {
    foreach ($GLOBALS['bridgeParity'] ?? [] as $server) {
        $server['process']?->stop(0);
        @unlink($server['dir'].'/index.php');
        @rmdir($server['dir']);
    }

    unset($GLOBALS['bridgeParity']);
});

it('parses a body exactly as PHP does', function (string $body, ?string $contentType = null) {
    $servers = $GLOBALS['bridgeParity'];

    if ($servers['php']['process'] === null || $servers['ours']['process'] === null) {
        $this->markTestSkipped('Could not start php -S servers on this machine.');
    }

    $contentType ??= Bridge::contentType();

    $php = parityPost($servers['php']['port'], $contentType, $body, false);
    $ours = parityPost($servers['ours']['port'], $contentType, $body, true);

    expect($ours)->toBe($php);
})->with(function () {
    $all = Bridge::allBytes();
    $B = Bridge::BOUNDARY;
    $mp = fn (array $parts, bool $close = true) => Bridge::multipart($parts, $close);
    $field = fn (string $name, string $value) => Bridge::field($name, $value);
    $file = fn (string $name, string $filename, string $content, string $type = 'application/octet-stream') => Bridge::file($name, $filename, $content, $type);

    return [
        'fields and a binary file' => [$mp([$field('a', '1'), $field('b[]', 'x'), $field('b[]', 'y'), $file('f', 'a.bin', $all."\r\n\r\n".$all)])],
        'nested files' => [$mp([$file('docs[a][]', 'one.txt', 'one'), $file('docs[a][]', 'two.txt', 'two'), $file('docs[b]', 'dir/three.txt', 'three', 'text/plain; charset=utf-8')])],
        'dots and spaces in names' => [$mp([$field('a.b c', 'v'), $field('x.y[z.w]', 'q'), $file('f.g h', 'n.txt', 'c')])],
        'index whitespace' => [$mp([$field('b[ c]', '1'), $file('g[ x]', 'b', '2'), $file('f[ ]', 'a', '1')])],
        'forbidden and numeric keys' => [$mp([$file('h[__Host-x]', 'c', '3'), $file(' j', 'a', '1'), $file('k[01]', 'b', '2'), $file('k[-1]', 'c', '3')])],
        'empty file input' => [$mp([$file('f', '', ''), $field('a', 'b')])],
        'zero-length file' => [$mp([$file('f', 'empty.txt', '')])],
        'upload_max_filesize' => [$mp([$file('f', 'big.bin', str_repeat('A', 6001)), $file('g', 'ok.bin', str_repeat('B', 6000))])],
        'MAX_FILE_SIZE' => [$mp([$field('MAX_FILE_SIZE', '100'), $file('f', 'a', str_repeat('A', 101)), $file('g', 'b', str_repeat('B', 100))])],
        'MAX_FILE_SIZE in lower case' => [$mp([$field('max_file_size', '10'), $file('f', 'a', str_repeat('A', 11))])],
        'MAX_FILE_SIZE with a leading space' => [$mp([$field(' MAX_FILE_SIZE', '5'), $file('h', 'h', 'toolong')])],
        // PHP reads 5119 bytes at a time: MAX_FILE_SIZE (5500) is lower, but
        // both limits are crossed in the second chunk and upload_max_filesize
        // is checked first.
        'MAX_FILE_SIZE and upload_max_filesize in one chunk' => [$mp([$field('MAX_FILE_SIZE', '5500'), $file('f', 'a', str_repeat('A', 7000))])],
        'MAX_FILE_SIZE crossed a chunk earlier' => [$mp([$field('MAX_FILE_SIZE', '5000'), $file('f', 'a', str_repeat('A', 7000))])],
        'max_file_uploads' => [$mp([$file('f1', 'a', '1'), $file('f2', '', ''), $file('f3', 'b', '2'), $file('f4', 'c', '3'), $file('f5', 'd', '4'), $file('f6', 'e', '5')])],
        'max_input_vars' => [$mp([$field('a', '1'), $field('b', '2'), $field('c', '3'), $field('d', '4'), $field('e', '5')])],
        'files beyond max_input_vars' => [$mp([$field('a', '1'), $field('b', '2'), $file('f1', 'x', '1'), $file('f2', 'y', '2'), $file('f3', 'z', '3'), $file('f4', 'w', '4')])],
        'max_input_nesting_level' => [$mp([$field('a[x]', 'kept'), $field('a[b][c][d][e][f]', '1'), $field('g', '2')])],
        'max_input_nesting_level for files' => [$mp([$file('a[x]', 'x', '0'), $file('a[b][c][d][e]', 'y', '1'), $file('g', 'z', '2')])],
        'post_max_size' => [$mp([$field('a', str_repeat('x', 300001))])],
        'body ends inside a file' => [$mp([$field('a', '1'), $file('f', 'a.bin', 'abc')], false)."--{$B}\r\nContent-Disposition: form-data; name=\"g\"; filename=\"cut.bin\"\r\n\r\npartial data"],
        'body ends inside a field' => ["--{$B}\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nno end"],
        'CRLFs and a fake delimiter in content' => [$mp([$file('f', 'x', "line1\r\n\r\nline2\r\n--{$B}-not\r\n"), $field('a', "v\r\n\r\nw")])],
        'NUL in a name' => [$mp(["Content-Disposition: form-data; name=\"a\0b\"\r\n\r\nv"])],
        'LF line endings' => [str_replace("\r\n", "\n", $mp([$field('a', '1'), $file('f', 'x.txt', 'data')]))],
        'preamble and epilogue' => ["junk\r\n".$mp([$field('a', '1')]).'epilogue'],
        'quoted parameters' => [$mp(["Content-Disposition: form-data; name=\"a;b\"; filename=\"q\\\"u'o;te.txt\"\r\n\r\nv", "content-disposition:form-data;name=c\r\n\r\nw", "Content-Disposition: form-data; name='single'\r\n\r\nx"])],
        'folded header' => [$mp(["Content-Disposition: form-data;\r\n name=\"folded\"\r\n\r\nv"])],
        'part without Content-Disposition' => [$mp(["Content-Type: text/plain\r\n\r\nv", $field('a', '1')])],
        'garbled part' => [$mp([$field('a', '1'), "Content-Disposition: form-data\r\n\r\nv", $field('b', '2')])],
        'file parts without names' => [$mp(["Content-Disposition: form-data; filename=\"anon.txt\"\r\n\r\nv", "Content-Disposition: form-data; filename=\"anon2.txt\"\r\n\r\nw"])],
        'broken brackets' => [$mp([$file('ok', 'w', 'f'), $file('f[a', 'x', 'c'), $file('g[a]b', 'y', 'd'), $file('late', 'z', 'e')])],
        'Windows path' => [$mp([$file('f', 'C:\\Users\\me\\pic.png', 'p')])],
        'same file name twice' => [$mp([$file('f', 'one', '1'), $file('f', 'two', '2')])],
        'file then array of the same name' => [$mp([$file('f', 'one', '1'), $file('f[x]', 'two', '2')])],
        'field after a file of the same name' => [$mp([$field('g', 'before'), $file('g', 'g.txt', 'G'), $field('g', 'after')])],
        'empty names' => [$mp([$field('', 'v'), $file('', 'x', 'c'), $field('[x]', 'w')])],
        'quoted boundary' => ["--{$B}\r\n".$field('a', '1')."\r\n--{$B}--", "multipart/form-data; boundary=\"{$B}\""],
        'boundary after charset' => [$mp([$field('a', '1')]), "multipart/form-data; charset=utf-8; boundary={$B}"],
        'missing boundary' => [$mp([$field('a', '1')]), 'multipart/form-data'],
        'unterminated quoted boundary' => [$mp([$field('a', '1')]), 'multipart/form-data; boundary="abc'],
        'urlencoded' => ['a=1&b[]=2&c[d]=e+f', 'application/x-www-form-urlencoded'],
        'urlencoded with NUL and dots' => ['g=%00h&i.j=k&%00x=1', 'application/x-www-form-urlencoded; charset=UTF-8'],
        'urlencoded over max_input_vars' => ['a=1&b[]=2&b[]=3&c[d]=e+f&g=h', 'application/x-www-form-urlencoded'],
        'urlencoded empty segments' => ['&a=1&&b&=c&', 'application/x-www-form-urlencoded'],
        'urlencoded nesting' => ['a[x]=1&a[b][c][d][e][f]=2', 'application/x-www-form-urlencoded'],
        'unterminated brackets' => ['a[b=1&c[d][e=2&f[ g]=3&h[.i j', 'application/x-www-form-urlencoded'],
        'unterminated brackets in multipart' => [$mp([$field('x[y', '1'), $field('z[a][b', '2'), $field('q[]r', '3')])],
        'text/plain' => ['a=1', 'text/plain'],
    ];
});
