<?php

use Illuminate\Http\UploadedFile;
use Native\Mobile\Http\Bridge\ParsedBody;
use Native\Mobile\Http\Bridge\RequestBodyParser;
use Symfony\Component\Process\Process;
use Tests\Support\Bridge;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/bridge-parser-'.bin2hex(random_bytes(4));
    mkdir($this->tempDir);
    $this->parsed = [];
    $this->parse = function (array $parts, array $options = [], string $method = 'POST', ?string $body = null): ParsedBody {
        $parsed = (new RequestBodyParser(...['tempDir' => $this->tempDir, ...$options]))
            ->parse($method, Bridge::contentType(), $body ?? Bridge::multipart($parts));

        return $this->parsed[] = $parsed;
    };
});

afterEach(function () {
    foreach ($this->parsed as $parsed) {
        $parsed->cleanup();
    }

    foreach (glob($this->tempDir.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->tempDir);
});

/**
 * Run the parser in a fresh PHP process with the given ini settings, since
 * upload limits and max_input_vars can't be changed at runtime.
 */
function parseInSubprocess(array $ini, string $method, string $contentType, string $body): array
{
    $script = <<<'PHP'
        require $argv[1];
        $parsed = (new Native\Mobile\Http\Bridge\RequestBodyParser)->parse($argv[2], $argv[3], stream_get_contents(STDIN));
        $files = $parsed->phpFiles;
        array_walk_recursive($files, function (&$value, $key) { if ($key === 'tmp_name' && $value !== '') { $value = 'TMP'; } });
        echo json_encode(['fields' => $parsed->fields, 'files' => $files, 'warnings' => $parsed->warnings]);
        $parsed->cleanup();
        PHP;

    $command = [PHP_BINARY];

    foreach ($ini as $key => $value) {
        $command[] = '-d';
        $command[] = "{$key}={$value}";
    }

    array_push($command, '-r', $script, dirname(__DIR__, 4).'/vendor/autoload.php', $method, $contentType);

    $process = new Process($command, null, null, $body);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

describe('urlencoded bodies', function () {
    it('parses fields the way PHP fills $_POST', function () {
        $parsed = (new RequestBodyParser)->parse(
            'POST',
            'application/x-www-form-urlencoded; charset=UTF-8',
            'a=1&b[]=2&b[]=3&c[d][e]=f+g&h=%00i%FF&j.k l=m&n',
        );

        expect($parsed->fields)->toBe([
            'a' => '1',
            'b' => ['2', '3'],
            'c' => ['d' => ['e' => 'f g']],
            'h' => "\0i\xFF",
            'j_k_l' => 'm',
            'n' => '',
        ])->and($parsed->files)->toBe([])->and($parsed->warnings)->toBe([]);
    });

    it('parses them for POST, PUT, PATCH, DELETE and QUERY but not GET or HEAD', function (string $method, array $expected) {
        $parsed = (new RequestBodyParser)->parse($method, 'application/x-www-form-urlencoded', 'a=1');

        expect($parsed->fields)->toBe($expected);
    })->with([
        ['POST', ['a' => '1']],
        ['PUT', ['a' => '1']],
        ['PATCH', ['a' => '1']],
        ['DELETE', ['a' => '1']],
        ['QUERY', ['a' => '1']],
        ['patch', ['a' => '1']],
        ['GET', []],
        ['HEAD', []],
    ]);

    it('leaves every other content type to the app', function (?string $contentType) {
        $parsed = (new RequestBodyParser)->parse('POST', $contentType, 'a=1');

        expect($parsed->fields)->toBe([])->and($parsed->files)->toBe([]);
    })->with([
        'json' => ['application/json'],
        'text' => ['text/plain'],
        'none' => [null],
        'look-alike' => ['application/x-www-form-urlencoded-ish'],
    ]);
});

describe('multipart bodies', function () {
    it('parses fields and files', function () {
        $parsed = ($this->parse)([
            Bridge::field('title', 'Hello'),
            Bridge::field('tags[]', 'a'),
            Bridge::field('tags[]', 'b'),
            Bridge::file('photo', 'photo.png', 'PNGDATA', 'image/png'),
        ]);

        expect($parsed->fields)->toBe(['title' => 'Hello', 'tags' => ['a', 'b']])
            ->and($parsed->warnings)->toBe([]);

        $photo = $parsed->files['photo'];

        expect($photo)->toBeInstanceOf(UploadedFile::class)
            ->and($photo->isValid())->toBeTrue()
            ->and($photo->getError())->toBe(UPLOAD_ERR_OK)
            ->and($photo->getClientOriginalName())->toBe('photo.png')
            ->and($photo->getClientMimeType())->toBe('image/png')
            ->and($photo->getSize())->toBe(7)
            ->and($photo->getContent())->toBe('PNGDATA')
            ->and(dirname($photo->getPathname()))->toBe(realpath($this->tempDir));
    });

    it('keeps binary file content and field values byte for byte', function () {
        $bytes = Bridge::allBytes()."\r\n\r\n".Bridge::allBytes()."\0";

        $parsed = ($this->parse)([
            Bridge::field('raw', $bytes),
            Bridge::file('blob', 'blob.bin', $bytes),
        ]);

        expect($parsed->fields['raw'])->toBe($bytes)
            ->and($parsed->files['blob']->getContent())->toBe($bytes)
            ->and(hash_file('sha256', $parsed->files['blob']->getPathname()))->toBe(hash('sha256', $bytes));
    });

    it('nests files by field name and lays them out in $_FILES like PHP', function () {
        $parsed = ($this->parse)([
            Bridge::file('docs[a][]', 'one.txt', 'one'),
            Bridge::file('docs[a][]', 'two.txt', 'two'),
            Bridge::file('docs[b]', 'dir/three.txt', 'three', 'text/plain; charset=utf-8'),
        ]);

        expect($parsed->files['docs']['a'][0]->getContent())->toBe('one')
            ->and($parsed->files['docs']['a'][1]->getContent())->toBe('two')
            ->and($parsed->files['docs']['b']->getClientOriginalName())->toBe('three.txt');

        $php = $parsed->phpFiles['docs'];

        expect(array_keys($php))->toBe(['name', 'full_path', 'type', 'tmp_name', 'error', 'size'])
            ->and($php['name'])->toBe(['a' => ['one.txt', 'two.txt'], 'b' => 'three.txt'])
            ->and($php['full_path'])->toBe(['a' => ['one.txt', 'two.txt'], 'b' => 'dir/three.txt'])
            ->and($php['type'])->toBe(['a' => ['application/octet-stream', 'application/octet-stream'], 'b' => 'text/plain'])
            ->and($php['tmp_name']['b'])->toBe($parsed->files['docs']['b']->getPathname())
            ->and($php['error'])->toBe(['a' => [0, 0], 'b' => 0])
            ->and($php['size'])->toBe(['a' => [3, 3], 'b' => 5]);
    });

    it('mangles dots and spaces in names as PHP does', function () {
        $parsed = ($this->parse)([
            Bridge::field('a.b c', 'v'),
            Bridge::field('x.y[z.w]', 'q'),
            Bridge::file('f.g h', 'n.txt', 'c'),
        ]);

        expect($parsed->fields)->toBe(['a_b_c' => 'v', 'x_y' => ['z.w' => 'q']])
            ->and($parsed->files)->toHaveKey('f_g_h');
    });

    it('turns an empty file input into null and UPLOAD_ERR_NO_FILE', function () {
        $parsed = ($this->parse)([Bridge::file('avatar', '', '')]);

        expect($parsed->files)->toBe(['avatar' => null])
            ->and($parsed->phpFiles['avatar'])->toBe([
                'name' => '', 'full_path' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0,
            ])
            ->and($parsed->tempPaths())->toBe([]);
    });

    it('accepts a zero-length file with a name', function () {
        $parsed = ($this->parse)([Bridge::file('f', 'empty.txt', '')]);

        expect($parsed->files['f']->isValid())->toBeTrue()
            ->and($parsed->files['f']->getSize())->toBe(0);
    });

    it('keeps only the basename as the client name, with the full path beside it', function () {
        $parsed = ($this->parse)([Bridge::file('f', 'C:\\Users\\me\\pic.png', 'p')]);

        expect($parsed->files['f']->getClientOriginalName())->toBe('pic.png')
            ->and($parsed->phpFiles['f']['name'])->toBe('pic.png')
            ->and($parsed->phpFiles['f']['full_path'])->toBe('C:\\Users\\me\\pic.png');
    });

    it('names a file part without a name by its position, as PHP does', function () {
        $parsed = ($this->parse)([
            "Content-Disposition: form-data; filename=\"a.txt\"\r\n\r\nA",
            "Content-Disposition: form-data; filename=\"b.txt\"\r\n\r\nB",
        ]);

        expect(array_keys($parsed->files))->toBe([0, 1]);
    });

    it('drops file parts with broken brackets, and every file after one', function () {
        $parsed = ($this->parse)([
            Bridge::file('before', 'a', 'a'),
            Bridge::file('bad[a', 'b', 'b'),
            Bridge::file('after', 'c', 'c'),
            Bridge::field('field', 'still parsed'),
        ]);

        expect(array_keys($parsed->files))->toBe(['before'])
            ->and($parsed->fields)->toBe(['field' => 'still parsed']);
    });

    it('stops at a part whose headers are garbled', function () {
        $parsed = ($this->parse)([
            Bridge::field('a', '1'),
            "Content-Disposition: form-data\r\n\r\nv",
            Bridge::field('b', '2'),
        ]);

        expect($parsed->fields)->toBe(['a' => '1'])
            ->and($parsed->warnings)->toBe(['File Upload Mime headers garbled']);
    });

    it('skips a part without Content-Disposition', function () {
        $parsed = ($this->parse)(["Content-Type: text/plain\r\n\r\nv", Bridge::field('a', '1')]);

        expect($parsed->fields)->toBe(['a' => '1']);
    });

    it('is parsed for PUT too, as Symfony 8 has request_parse_body() do', function () {
        $parsed = ($this->parse)([Bridge::field('a', '1'), Bridge::file('f', 'f.txt', 'x')], method: 'PUT');

        expect($parsed->fields)->toBe(['a' => '1'])
            ->and($parsed->files['f']->getContent())->toBe('x');
    });

    it('is not parsed for GET', function () {
        $parsed = ($this->parse)([Bridge::field('a', '1')], method: 'GET');

        expect($parsed->fields)->toBe([])->and($parsed->files)->toBe([]);
    });

    it('warns when the boundary is missing', function () {
        $parsed = (new RequestBodyParser)->parse('POST', 'multipart/form-data', Bridge::multipart([Bridge::field('a', '1')]));

        expect($parsed->fields)->toBe([])
            ->and($parsed->warnings)->toBe(['Missing boundary in multipart/form-data POST data']);
    });
});

describe('limits', function () {
    it('parses nothing when the body is over post_max_size', function (string $contentType, string $body) {
        $parsed = (new RequestBodyParser(postMaxSize: 20))->parse('POST', $contentType, $body);

        expect($parsed->fields)->toBe([])
            ->and($parsed->files)->toBe([])
            ->and($parsed->warnings)->toBe([sprintf('POST Content-Length of %d bytes exceeds the limit of 20 bytes', strlen($body))]);
    })->with([
        'urlencoded' => ['application/x-www-form-urlencoded', 'a=123456789012345678901'],
        'multipart' => [Bridge::contentType(), Bridge::multipart([Bridge::field('a', '1')])],
    ]);

    it('allows a body exactly at post_max_size', function () {
        $parsed = (new RequestBodyParser(postMaxSize: 3))->parse('POST', 'application/x-www-form-urlencoded', 'a=1');

        expect($parsed->fields)->toBe(['a' => '1']);
    });

    it('gives UPLOAD_ERR_INI_SIZE for a file over upload_max_filesize and writes no temp file', function () {
        $parsed = ($this->parse)([
            Bridge::file('big', 'big.bin', str_repeat('A', 101)),
            Bridge::file('fits', 'fits.bin', str_repeat('B', 100)),
        ], ['uploadMaxFilesize' => 100]);

        expect($parsed->files['big']->getError())->toBe(UPLOAD_ERR_INI_SIZE)
            ->and($parsed->files['big']->isValid())->toBeFalse()
            ->and($parsed->phpFiles['big'])->toBe([
                'name' => 'big.bin', 'full_path' => 'big.bin', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0,
            ])
            ->and($parsed->files['fits']->isValid())->toBeTrue()
            ->and($parsed->tempPaths())->toHaveCount(1)
            ->and(glob($this->tempDir.'/*'))->toHaveCount(1);
    });

    it('gives UPLOAD_ERR_FORM_SIZE for a file over a MAX_FILE_SIZE field, whatever its case', function (string $field) {
        $parsed = ($this->parse)([
            Bridge::field($field, '10'),
            Bridge::file('big', 'big.bin', str_repeat('A', 11)),
            Bridge::file('fits', 'fits.bin', str_repeat('B', 10)),
        ]);

        expect($parsed->files['big']->getError())->toBe(UPLOAD_ERR_FORM_SIZE)
            ->and($parsed->phpFiles['big']['size'])->toBe(0)
            ->and($parsed->files['fits']->isValid())->toBeTrue()
            ->and($parsed->fields)->toBe([$field => '10']);
    })->with(['MAX_FILE_SIZE', 'max_file_size']);

    it('only applies MAX_FILE_SIZE to files after the field', function () {
        $parsed = ($this->parse)([
            Bridge::file('first', 'a', str_repeat('A', 50)),
            Bridge::field('MAX_FILE_SIZE', '10'),
            Bridge::file('second', 'b', str_repeat('B', 50)),
        ]);

        expect($parsed->files['first']->isValid())->toBeTrue()
            ->and($parsed->files['second']->getError())->toBe(UPLOAD_ERR_FORM_SIZE);
    });

    it('reports whichever size limit PHP would cross first while reading', function (int $uploadMax, string $maxFileSize, int $size, int $error) {
        $parsed = ($this->parse)([
            Bridge::field('MAX_FILE_SIZE', $maxFileSize),
            Bridge::file('f', 'f.bin', str_repeat('A', $size)),
        ], ['uploadMaxFilesize' => $uploadMax]);

        expect($parsed->files['f']->getError())->toBe($error);
    })->with([
        // PHP reads 5119 bytes at a time and checks upload_max_filesize first.
        'both crossed in the first chunk' => [100, '50', 200, UPLOAD_ERR_INI_SIZE],
        'MAX_FILE_SIZE crossed a chunk earlier' => [20000, '5000', 30000, UPLOAD_ERR_FORM_SIZE],
        'upload_max_filesize crossed a chunk earlier' => [5000, '20000', 30000, UPLOAD_ERR_INI_SIZE],
        'MAX_FILE_SIZE of 0 means no limit' => [0, '0', 30000, UPLOAD_ERR_OK],
        'MAX_FILE_SIZE is read like strtoll' => [0, ' 12abc', 13, UPLOAD_ERR_FORM_SIZE],
    ]);

    it('ignores files past max_file_uploads, but empty inputs do not count', function () {
        $parsed = ($this->parse)([
            Bridge::file('f1', 'a', '1'),
            Bridge::file('empty', '', ''),
            Bridge::file('f2', 'b', '2'),
            Bridge::file('f3', 'c', '3'),
            Bridge::file('f4', 'd', '4'),
            Bridge::field('after', 'kept'),
        ], ['maxFileUploads' => 2]);

        expect(array_keys($parsed->files))->toBe(['f1', 'empty', 'f2'])
            ->and($parsed->fields)->toBe(['after' => 'kept'])
            ->and($parsed->warnings)->toBe(['Maximum number of allowable file uploads has been exceeded'])
            ->and($parsed->tempPaths())->toHaveCount(2);
    });

    it('gives UPLOAD_ERR_PARTIAL when the body ends inside a file', function () {
        $body = Bridge::multipart([Bridge::field('a', '1'), Bridge::file('whole', 'w', 'abc')], close: false)
            .'--'.Bridge::BOUNDARY."\r\nContent-Disposition: form-data; name=\"cut\"; filename=\"cut.bin\"\r\n\r\npartial data";

        $parsed = ($this->parse)([], body: $body);

        expect($parsed->fields)->toBe(['a' => '1'])
            ->and($parsed->files['whole']->isValid())->toBeTrue()
            ->and($parsed->files['cut']->getError())->toBe(UPLOAD_ERR_PARTIAL)
            ->and($parsed->phpFiles['cut']['tmp_name'])->toBe('')
            ->and($parsed->tempPaths())->toHaveCount(1);
    });

    it('keeps max_input_vars fields and warns, as PHP does', function (string $contentType, string $body, array $expected) {
        $result = parseInSubprocess(['max_input_vars' => 3], 'POST', $contentType, $body);

        expect($result['fields'])->toBe($expected)
            ->and($result['warnings'])->toBe(['Input variables exceeded 3. To increase the limit change max_input_vars in php.ini.']);
    })->with([
        'multipart' => [Bridge::contentType(), Bridge::multipart([
            Bridge::field('a', '1'), Bridge::field('b', '2'), Bridge::field('c', '3'), Bridge::field('d', '4'), Bridge::field('e', '5'),
        ]), ['a' => '1', 'b' => '2', 'c' => '3']],
        // php_std_post_handler() counts after registering, so it keeps one more.
        'urlencoded' => ['application/x-www-form-urlencoded', 'a=1&b=2&c=3&d=4&e=5', ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']],
    ]);

    it('does not count files against max_input_vars', function () {
        $result = parseInSubprocess(['max_input_vars' => 1], 'POST', Bridge::contentType(), Bridge::multipart([
            Bridge::field('a', '1'), Bridge::file('f', 'f.txt', 'x'), Bridge::file('g', 'g.txt', 'y'),
        ]));

        expect($result['fields'])->toBe(['a' => '1'])
            ->and(array_keys($result['files']))->toBe(['f', 'g'])
            ->and($result['warnings'])->toBe([]);
    });

    it('reads its limits from php.ini when none are passed', function () {
        $result = parseInSubprocess(
            ['upload_max_filesize' => '1K', 'max_file_uploads' => '1', 'post_max_size' => '1M'],
            'POST',
            Bridge::contentType(),
            Bridge::multipart([Bridge::file('big', 'a', str_repeat('A', 1025)), Bridge::file('late', 'b', 'b')]),
        );

        expect($result['files'])->toBe(['big' => [
            'name' => 'a', 'full_path' => 'a', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0,
        ]])->and($result['warnings'])->toBe(['Maximum number of allowable file uploads has been exceeded']);

        $tooBig = parseInSubprocess(['post_max_size' => '10'], 'POST', 'application/x-www-form-urlencoded', 'a=1234567890');

        expect($tooBig['fields'])->toBe([])
            ->and($tooBig['warnings'])->toBe(['POST Content-Length of 12 bytes exceeds the limit of 10 bytes']);
    });

    it('stops at max_multipart_body_parts', function () {
        $result = parseInSubprocess(['max_multipart_body_parts' => 2], 'POST', Bridge::contentType(), Bridge::multipart([
            Bridge::field('a', '1'), Bridge::field('b', '2'), Bridge::field('c', '3'),
        ]));

        expect($result['fields'])->toBe(['a' => '1', 'b' => '2'])
            ->and($result['warnings'])->toBe(['Multipart body parts limit exceeded 2. To increase the limit change max_multipart_body_parts in php.ini.']);
    });
});

describe('temp files', function () {
    it('deletes them on cleanup', function () {
        $parsed = ($this->parse)([Bridge::file('a', 'a', 'A'), Bridge::file('b', 'b', 'B')]);
        $paths = $parsed->tempPaths();

        expect($paths)->toHaveCount(2)->each->toBeFile();

        $parsed->cleanup();

        foreach ($paths as $path) {
            expect($path)->not->toBeFile();
        }
    });

    it('leaves a file the app moved', function () {
        $parsed = ($this->parse)([Bridge::file('a', 'a.txt', 'kept')]);
        $moved = $parsed->files['a']->move($this->tempDir, 'moved.txt');

        $parsed->cleanup();

        expect($moved->getPathname())->toBeFile()
            ->and(file_get_contents($moved->getPathname()))->toBe('kept');
    });

    it('also cleans up a file a later part with the same name replaced', function () {
        $parsed = ($this->parse)([Bridge::file('f', 'one', '1'), Bridge::file('f', 'two', '2')]);

        expect($parsed->files['f']->getContent())->toBe('2')
            ->and($parsed->tempPaths())->toHaveCount(2);

        $parsed->cleanup();

        expect(glob($this->tempDir.'/*'))->toBe([]);
    });

    it('falls back to the system temp dir when the one given is unusable', function () {
        $parsed = (new RequestBodyParser(tempDir: $this->tempDir.'/missing'))
            ->parse('POST', Bridge::contentType(), Bridge::multipart([Bridge::file('f', 'f', 'x')]));
        $this->parsed[] = $parsed;

        expect(dirname($parsed->files['f']->getPathname()))->toBe(realpath(sys_get_temp_dir()));
    });
});
