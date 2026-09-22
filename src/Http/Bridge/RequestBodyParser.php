<?php

namespace Native\Mobile\Http\Bridge;

use Illuminate\Http\UploadedFile;

/**
 * Turns a raw request body into $_POST fields and uploaded files, doing what
 * PHP's SAPI does before a script runs. Embedded runtimes never get that step:
 * the body only reaches them as bytes, so this class stands in for it. The
 * results match PHP's own parser (main/rfc1867.c and main/php_variables.c),
 * quirks included.
 *
 * Limits are PHP's own and give PHP's own results: post_max_size,
 * upload_max_filesize, a MAX_FILE_SIZE form field, max_file_uploads,
 * max_input_vars, max_input_nesting_level and max_multipart_body_parts.
 */
final class RequestBodyParser
{
    /**
     * The methods whose form bodies are parsed. PHP parses POST bodies itself;
     * Symfony 8 passes PUT, DELETE, PATCH and QUERY bodies to
     * request_parse_body(), which parses them the same way.
     */
    public const METHODS = ['POST', 'PUT', 'DELETE', 'PATCH', 'QUERY'];

    /** rfc1867.c reads file data in chunks of FILLUNIT - 1 bytes and checks the size limits after each one. */
    private const READ_CHUNK = 5119;

    /** @var list<string> */
    private const FILE_KEYS = ['name', 'full_path', 'type', 'tmp_name', 'error', 'size'];

    /**
     * Every argument left null is read from php.ini. upload_max_filesize,
     * post_max_size and max_file_uploads can only be set per directory, so
     * a runtime that wants other values passes them here.
     */
    public function __construct(
        private ?string $tempDir = null,
        private ?int $uploadMaxFilesize = null,
        private ?int $postMaxSize = null,
        private ?int $maxFileUploads = null,
    ) {}

    /**
     * application/x-www-form-urlencoded and multipart/form-data bodies sent
     * with one of METHODS become fields and files. Anything else gives an
     * empty ParsedBody and stays in the raw body for the app to read.
     *
     * $length is the body's real length when $body is not the whole of it,
     * as when read() left out a body over post_max_size. A body over
     * post_max_size gives an empty ParsedBody and PHP's warning.
     */
    public function parse(string $method, ?string $contentType, string $body, ?int $length = null): ParsedBody
    {
        $length ??= strlen($body);

        if ($this->exceedsPostMaxSize($length)) {
            return new ParsedBody([], [], warnings: [sprintf(
                'POST Content-Length of %d bytes exceeds the limit of %d bytes',
                $length,
                $this->postMaxSize(),
            )]);
        }

        $mediaType = self::mediaType($contentType ?? '');
        $urlencoded = $mediaType === 'application/x-www-form-urlencoded';

        if ((! $urlencoded && $mediaType !== 'multipart/form-data')
            || ! in_array(strtoupper($method), self::METHODS, true)) {
            return new ParsedBody([], []);
        }

        return $urlencoded
            ? $this->parseUrlencoded($body)
            : $this->parseMultipart((string) $contentType, $body);
    }

    /**
     * Read a request body the way PHP does before a script runs: whole when it
     * fits in post_max_size, not at all when it doesn't. PHP then leaves
     * php://input, $_POST and $_FILES empty. A body over the limit is counted
     * as it streams past and never held, so its real length still comes back
     * for CONTENT_LENGTH, which is what Laravel's ValidatePostSize checks
     * before it answers 413.
     *
     * @return array{string, int} the body ('' when over the limit) and its real length
     */
    public function read(string $stream = 'php://input'): array
    {
        $handle = @fopen($stream, 'rb');

        if ($handle === false) {
            return ['', 0];
        }

        $body = '';
        $length = 0;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1 << 20);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $length += strlen($chunk);

                // Once over the limit, stop keeping bytes and only count them.
                if ($this->exceedsPostMaxSize($length)) {
                    $body = '';
                } else {
                    $body .= $chunk;
                }
            }
        } finally {
            fclose($handle);
        }

        return [$body, $length];
    }

    /** Whether PHP would refuse to read a body of $length bytes. post_max_size 0 means no limit. */
    public function exceedsPostMaxSize(int $length): bool
    {
        $limit = $this->postMaxSize();

        return $limit > 0 && $length > $limit;
    }

    private function postMaxSize(): int
    {
        return $this->postMaxSize ?? self::iniBytes('post_max_size');
    }

    /**
     * The media type the way SAPI.c matches it: lower-cased and cut at the
     * first ";", "," or space.
     */
    public static function mediaType(string $contentType): string
    {
        $contentType = trim($contentType);

        return strtolower(substr($contentType, 0, strcspn($contentType, ';, ')));
    }

    private function parseUrlencoded(string $body): ParsedBody
    {
        $warnings = [];
        $fields = [];

        // php_std_post_handler() splits on "&" only, whatever
        // arg_separator.input says, and every segment counts as a variable.
        $segments = explode('&', $body);

        if (end($segments) === '') {
            array_pop($segments);
        }

        $maxVars = self::maxInputVars();
        $maxNesting = self::maxInputNesting();
        $count = 0;

        foreach ($segments as $segment) {
            $pair = explode('=', $segment, 2);
            self::registerVariable($fields, urldecode($pair[0]), urldecode($pair[1] ?? ''), $maxNesting, $warnings);

            // PHP counts after registering, so it keeps one variable more
            // than max_input_vars before it stops.
            if (++$count > $maxVars) {
                $warnings[] = self::inputVarsWarning($maxVars);
                break;
            }
        }

        return new ParsedBody($fields, [], warnings: $warnings);
    }

    private function parseMultipart(string $contentType, string $body): ParsedBody
    {
        $warnings = [];
        $boundary = MultipartParser::boundary($contentType);

        if ($boundary === null) {
            return new ParsedBody([], [], warnings: [self::boundaryWarning($contentType)]);
        }

        $maxVars = self::maxInputVars();
        $maxNesting = self::maxInputNesting();
        $maxUploads = $this->maxFileUploads ?? (int) ini_get('max_file_uploads');
        $uploadMaxFilesize = $this->uploadMaxFilesize ?? self::iniBytes('upload_max_filesize');
        $partsLeft = self::maxBodyParts($maxVars, $maxUploads);

        $fields = [];
        $uploads = [];
        $tempPaths = [];
        $fieldCount = 0;
        $maxFileSize = 0;
        $uploadsLeft = $maxUploads;
        $anonymous = 0;

        // rfc1867.c never clears this once set, so after one file part is
        // skipped every later file part is skipped too.
        $skip = false;

        foreach ((new MultipartParser)->parts($body, $boundary) as $part) {
            if (! isset($part['headers']['content-disposition'])) {
                continue;
            }

            if (--$partsLeft < 0) {
                $warnings[] = sprintf(
                    'Multipart body parts limit exceeded %d. To increase the limit change max_multipart_body_parts in php.ini.',
                    self::maxBodyParts($maxVars, $maxUploads),
                );
                break;
            }

            $name = $part['name'];
            $filename = $part['filename'];

            if ($filename === null && $name !== null) {
                if (++$fieldCount <= $maxVars) {
                    self::registerVariable($fields, $name, $part['content'], $maxNesting, $warnings);
                } elseif ($fieldCount === $maxVars + 1) {
                    $warnings[] = self::inputVarsWarning($maxVars);
                }

                if (strcasecmp($name, 'MAX_FILE_SIZE') === 0) {
                    $maxFileSize = self::strtoll($part['content']);
                }

                continue;
            }

            if ($uploadsLeft <= 0) {
                $skip = true;

                if ($uploadsLeft === 0) {
                    $uploadsLeft--;
                    $warnings[] = 'Maximum number of allowable file uploads has been exceeded';
                }
            }

            if ($name === null && $filename === null) {
                $warnings[] = 'File Upload Mime headers garbled';
                break;
            }

            $name ??= (string) $anonymous++;
            $filename ??= '';

            if (! $skip && ! self::bracketsAreBalanced($name)) {
                $skip = true;
            }

            if ($skip) {
                continue;
            }

            $error = UPLOAD_ERR_OK;
            $tempPath = null;
            $size = 0;

            if ($filename === '') {
                $error = UPLOAD_ERR_NO_FILE;
            } else {
                $uploadsLeft--;
                $tempPath = $this->createTempFile();

                if ($tempPath === null) {
                    $error = UPLOAD_ERR_NO_TMP_DIR;
                } else {
                    $size = strlen($part['content']);
                    $error = self::sizeError($size, $uploadMaxFilesize, $maxFileSize);

                    if ($error === UPLOAD_ERR_OK && ! $part['complete']) {
                        $error = UPLOAD_ERR_PARTIAL;
                    }

                    if ($error === UPLOAD_ERR_OK && @file_put_contents($tempPath, $part['content']) !== $size) {
                        $error = UPLOAD_ERR_CANT_WRITE;
                    }

                    if ($error === UPLOAD_ERR_OK) {
                        $tempPaths[] = $tempPath;
                    } else {
                        @unlink($tempPath);
                        $tempPath = null;
                        $size = 0;
                    }
                }
            }

            $uploads[] = [
                'field' => self::normalizeName($name),
                'name' => self::basename($filename),
                'full_path' => $filename,
                'type' => $error === UPLOAD_ERR_OK ? (string) $part['type'] : '',
                'tmp_name' => $tempPath ?? '',
                'error' => $error,
                'size' => $size,
            ];
        }

        [$files, $phpFiles] = self::fileTrees($uploads, $maxNesting, $warnings);

        return new ParsedBody($fields, $files, $tempPaths, $phpFiles, $warnings);
    }

    /**
     * Nest the uploads by field name, both as UploadedFile objects and in
     * PHP's own $_FILES layout, where each of name, type, tmp_name and so on
     * carries its own copy of the nesting.
     *
     * @param  list<array{field: string, name: string, full_path: string, type: string, tmp_name: string, error: int, size: int}>  $uploads
     * @param  list<string>  $warnings
     * @return array{array<array-key, mixed>, array<array-key, mixed>}
     */
    private static function fileTrees(array $uploads, int $maxNesting, array &$warnings): array
    {
        if ($uploads === []) {
            return [[], []];
        }

        // Register each upload's index under its field name to get PHP's
        // nesting, then swap each index for the upload it stands for. PHP
        // registers "field[name][...]" and so on, one level deeper than the
        // field name, so the nesting limit is one lower here.
        $shape = [];

        foreach ($uploads as $index => $upload) {
            self::registerVariable($shape, $upload['field'], (string) $index, $maxNesting - 1, $warnings);
        }

        $objects = array_map(fn (array $upload) => $upload['error'] === UPLOAD_ERR_NO_FILE
            ? null
            : new UploadedFile($upload['tmp_name'], $upload['full_path'], $upload['type'], $upload['error'], true),
            $uploads,
        );

        $files = self::mapLeaves($shape, fn (string $index) => $objects[(int) $index]);

        $phpFiles = [];

        foreach ($shape as $key => $node) {
            if (! is_array($node)) {
                $phpFiles[$key] = array_intersect_key($uploads[(int) $node], array_flip(self::FILE_KEYS));

                continue;
            }

            foreach (self::FILE_KEYS as $attribute) {
                $phpFiles[$key][$attribute] = self::mapLeaves($node, fn (string $index) => $uploads[(int) $index][$attribute]);
            }
        }

        return [$files, $phpFiles];
    }

    /**
     * main/php_variables.c php_register_variable_ex(): put $value into $tree
     * under the input variable name $name, the way PHP fills $_POST and
     * $_FILES. That covers bracket syntax, "[]" appends, numeric keys, dots
     * and spaces in the base name becoming "_", and max_input_nesting_level.
     *
     * @param  array<array-key, mixed>  $tree
     * @param  list<string>  $warnings
     */
    private static function registerVariable(array &$tree, string $name, string $value, int $maxNesting, array &$warnings): void
    {
        // A C string to PHP: it ends at the first NUL.
        $nul = strpos($name, "\0");
        $name = ltrim($nul === false ? $name : substr($name, 0, $nul), ' ');
        $length = strlen($name);
        $bracket = strpos($name, '[');
        $base = strtr($bracket === false ? $name : substr($name, 0, $bracket), ' .', '__');

        if ($base === '') {
            return;
        }

        $target = &$tree;
        $index = $base;

        if ($bracket !== false) {
            $at = $bracket;
            $nesting = 0;

            while (true) {
                if (++$nesting > $maxNesting) {
                    unset($tree[$base]);
                    $warnings[] = sprintf(
                        'Input variable nesting level exceeded %d. To increase the limit change max_input_nesting_level in php.ini.',
                        self::maxInputNesting(),
                    );

                    return;
                }

                $start = ++$at;

                if ($at < $length && ctype_space($name[$at])) {
                    $at++;
                }

                if ($at < $length && $name[$at] === ']') {
                    $next = null;
                } else {
                    $close = strpos($name, ']', $at);

                    if ($close === false) {
                        // Not an index after all. At the top level the "[" and
                        // the rest become part of the name; deeper down they
                        // are dropped.
                        if ($nesting === 1) {
                            $index = $base.'_'.strtr(substr($name, $start), ' .[', '___');
                        }

                        break;
                    }

                    $next = substr($name, $start, $close - $start);
                    $at = $close;
                }

                if ($index === null) {
                    $target[] = [];
                    $target = &$target[array_key_last($target)];
                } else {
                    if (self::isForbiddenName($index, $name)) {
                        return;
                    }

                    if (! isset($target[$index]) || ! is_array($target[$index])) {
                        $target[$index] = [];
                    }

                    $target = &$target[$index];
                }

                $index = $next;

                if (++$at >= $length || $name[$at] !== '[') {
                    break;
                }
            }
        }

        if ($index === null) {
            $target[] = $value;
        } elseif (! self::isForbiddenName($index, $name)) {
            $target[$index] = $value;
        }
    }

    /**
     * rfc1867.c normalize_protected_variable(), which PHP applies to the name
     * of every file part before registering it in $_FILES: leading spaces go,
     * spaces and dots before the first "[" become "_", whitespace at the start
     * of each index goes, and anything after the last index is dropped.
     *
     * Field names skip this. With the filter extension loaded, which Laravel
     * requires, its input filter registers fields itself under the raw name.
     */
    private static function normalizeName(string $name): string
    {
        $name = ltrim($name, ' ');
        $bracket = strpos($name, '[');

        if ($bracket === false) {
            return strtr($name, ' .', '__');
        }

        $normalized = strtr(substr($name, 0, $bracket), ' .', '__').'[';
        $rest = substr($name, $bracket + 1);

        while (true) {
            $rest = ltrim($rest, " \r\n\t");
            $close = strpos($rest, ']');

            if ($close === false) {
                return $normalized.$rest;
            }

            $normalized .= substr($rest, 0, $close + 1);
            $rest = substr($rest, $close + 1);

            if (! str_starts_with($rest, '[')) {
                return $normalized;
            }

            $normalized .= '[';
            $rest = substr($rest, 1);
        }
    }

    /** php_is_forbidden_variable_name(): no key may pose as a __Host- or __Secure- cookie. */
    private static function isForbiddenName(string $key, string $name): bool
    {
        foreach (['__Host-', '__Secure-'] as $prefix) {
            if (str_starts_with($key, $prefix) && ! str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @return array<array-key, mixed>
     */
    private static function mapLeaves(array $tree, callable $map): array
    {
        foreach ($tree as $key => $node) {
            $tree[$key] = is_array($node) ? self::mapLeaves($node, $map) : $map($node);
        }

        return $tree;
    }

    /**
     * Which size limit a file of $size bytes breaks. PHP checks both limits
     * after every chunk it reads and stops at the first one exceeded, with
     * upload_max_filesize checked first within a chunk. So a MAX_FILE_SIZE
     * lower than upload_max_filesize wins when it is crossed in an earlier
     * chunk.
     */
    private static function sizeError(int $size, int $uploadMaxFilesize, int $maxFileSize): int
    {
        if ($size === 0) {
            return UPLOAD_ERR_OK;
        }

        $iniChunk = $uploadMaxFilesize > 0 ? self::chunkExceeding($size, $uploadMaxFilesize) : null;
        $formChunk = $maxFileSize !== 0 ? self::chunkExceeding($size, $maxFileSize) : null;

        if ($iniChunk !== null && ($formChunk === null || $iniChunk <= $formChunk)) {
            return UPLOAD_ERR_INI_SIZE;
        }

        return $formChunk !== null ? UPLOAD_ERR_FORM_SIZE : UPLOAD_ERR_OK;
    }

    /** The 1-based chunk after which a running total of $size bytes first exceeds $limit. */
    private static function chunkExceeding(int $size, int $limit): ?int
    {
        if ($size <= $limit) {
            return null;
        }

        return $limit < 0 ? 1 : intdiv($limit, self::READ_CHUNK) + 1;
    }

    /** rfc1867.c: a file part whose name has unbalanced brackets, or text after a "]", is dropped. */
    private static function bracketsAreBalanced(string $name): bool
    {
        $depth = 0;
        $length = strlen($name);

        for ($i = 0; $i < $length; $i++) {
            if ($name[$i] === '[') {
                $depth++;
            } elseif ($name[$i] === ']') {
                $depth--;

                if ($i + 1 < $length && $name[$i + 1] !== '[') {
                    return false;
                }
            }

            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /** rfc1867.c php_ap_basename(): whatever follows the last "/" or "\". */
    private static function basename(string $path): string
    {
        $slash = strrpos($path, '/');
        $backslash = strrpos($path, '\\');
        $cut = max($slash === false ? -1 : $slash, $backslash === false ? -1 : $backslash);

        return substr($path, $cut + 1);
    }

    private function createTempFile(): ?string
    {
        $directory = $this->tempDir ?? ((string) ini_get('upload_tmp_dir'));

        if ($directory === '' || ! is_dir($directory) || ! is_writable($directory)) {
            $directory = sys_get_temp_dir();
        }

        $path = @tempnam($directory, 'php');

        return $path === false ? null : $path;
    }

    private static function maxInputVars(): int
    {
        return (int) ini_get('max_input_vars');
    }

    private static function maxInputNesting(): int
    {
        return (int) ini_get('max_input_nesting_level');
    }

    private static function maxBodyParts(int $maxVars, int $maxUploads): int
    {
        $limit = ini_get('max_multipart_body_parts');

        if ($limit === false) {
            return PHP_INT_MAX;
        }

        return (int) $limit < 0 ? $maxVars + $maxUploads : (int) $limit;
    }

    /** The warning PHP gives for a multipart Content-Type whose boundary it can't use. */
    private static function boundaryWarning(string $contentType): string
    {
        $at = stripos($contentType, 'boundary');
        $equals = $at === false ? false : strpos($contentType, '=', $at);

        if ($equals === false) {
            return 'Missing boundary in multipart/form-data POST data';
        }

        return str_starts_with(substr($contentType, $equals + 1), '"')
            ? 'Invalid boundary in multipart/form-data POST data'
            : 'Boundary too large in multipart/form-data POST data';
    }

    private static function inputVarsWarning(int $maxVars): string
    {
        return sprintf('Input variables exceeded %d. To increase the limit change max_input_vars in php.ini.', $maxVars);
    }

    private static function iniBytes(string $key): int
    {
        return (int) @ini_parse_quantity((string) ini_get($key));
    }

    /** C's strtoll(value, NULL, 10), which is how PHP reads MAX_FILE_SIZE. */
    private static function strtoll(string $value): int
    {
        return preg_match('/^[ \t\n\r\x0B\x0C]*([+-]?\d+)/', $value, $match) === 1 ? (int) $match[1] : 0;
    }
}
