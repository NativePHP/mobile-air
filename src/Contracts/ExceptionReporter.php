<?php

namespace Native\Mobile\Contracts;

/**
 * Receives throwables that were caught and rendered (or swallowed) by the
 * runtime, so an external observer — the devtools listener on the dev
 * machine, ultimately an AI agent — can see them.
 *
 * Core never binds an implementation; when nothing is bound, reporting is
 * a no-op. The nativephp/devtools plugin binds one in debug builds.
 */
interface ExceptionReporter
{
    /**
     * @param  array<string, mixed>  $context  Where the throwable surfaced:
     *                                         mode (edge|webview|boot), screen class, url/method, etc.
     */
    public function report(\Throwable $e, array $context = []): void;
}
