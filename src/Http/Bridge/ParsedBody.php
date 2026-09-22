<?php

namespace Native\Mobile\Http\Bridge;

/**
 * What PHP's SAPI would have made of a request body before any userland code
 * ran: the $_POST fields, the uploaded files, and the temp files behind them.
 */
final class ParsedBody
{
    /**
     * @param  array<array-key, mixed>  $fields  Nested exactly like $_POST.
     * @param  array<array-key, mixed>  $files  Nested like the field names. Leaves are
     *                                          \Illuminate\Http\UploadedFile, or null for
     *                                          an empty file input (UPLOAD_ERR_NO_FILE).
     * @param  list<string>  $tempPaths  Every temp file written, including ones a
     *                                   later part with the same name replaced.
     * @param  array<array-key, mixed>  $phpFiles  The same uploads in PHP's own $_FILES
     *                                             layout (name, full_path, type, tmp_name,
     *                                             error, size).
     * @param  list<string>  $warnings  The warnings PHP itself would have raised while
     *                                  reading the body, such as a limit being hit.
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $files,
        private readonly array $tempPaths = [],
        public readonly array $phpFiles = [],
        public readonly array $warnings = [],
    ) {}

    /** @return list<string> */
    public function tempPaths(): array
    {
        return $this->tempPaths;
    }

    /**
     * Delete the temp files the app didn't move away, as PHP does when a
     * request ends. A moved file is gone from its temp path, so it is skipped.
     */
    public function cleanup(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
