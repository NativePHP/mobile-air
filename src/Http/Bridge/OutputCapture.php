<?php

namespace Native\Mobile\Http\Bridge;

use Closure;
use Throwable;

/**
 * Collects everything a piece of code echoes, so it can't land in front of a
 * raw HTTP response that is written afterwards.
 *
 * ob_flush() and flush() inside the callback stay in the capture, because the
 * buffer's handler keeps what it is given and passes nothing on. Output the
 * callback cleans away is dropped. Buffers the callback leaves open are
 * flushed into the capture, as PHP flushes them at the end of a request.
 */
final class OutputCapture
{
    /**
     * Run $callback and return what it returns. What it echoed goes into
     * $output. If it throws, the output is dropped, the buffer level is put
     * back and the exception is rethrown.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     *
     * @param-out string  $output
     *
     * @return T
     */
    public static function run(Closure $callback, ?string &$output = null): mixed
    {
        $captured = '';
        $level = ob_get_level();

        ob_start(static function (string $buffer, int $phase) use (&$captured): string {
            if (($phase & PHP_OUTPUT_HANDLER_CLEAN) === 0) {
                $captured .= $buffer;
            }

            return '';
        });

        try {
            $result = $callback();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            $output = '';

            throw $e;
        }

        while (ob_get_level() > $level) {
            ob_end_flush();
        }

        $output = $captured;

        return $result;
    }
}
