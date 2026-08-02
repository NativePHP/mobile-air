<?php

namespace Native\Mobile\Edge\Recording;

/**
 * POC session recorder — "the UI is data."
 *
 * Appends every published tree, dispatched event, and navigation to an
 * append-only JSONL stream. The format is sink-agnostic by design: the
 * same lines can go to a file (bug reports, replay), an HTTP batch
 * (telemetry), or a websocket (live mirroring). This POC ships the file
 * sink only.
 *
 * Frame kinds:
 *   meta  — once per recording: app/platform/entry screen
 *   tree  — a published wire tree (web target trees are always FULL
 *           frames; device recordings will interleave REUSE deltas and
 *           keyframes exactly like the wire protocol does)
 *   event — a dispatched UI event, annotated with the resolved method
 *           name so recordings are self-describing
 *   nav   — a navigation intent (redirect/back)
 *
 * Enabled via EDGE_RECORD=true. One file per (web) session:
 * storage/app/edge-recordings/<session>.jsonl
 *
 * NOT YET HERE (deliberately, this is a POC): prop redaction at write
 * time (`sensitive` markers), size caps/rotation, device-side wiring
 * (same tap points: the publish call sites + dispatch()).
 */
class TreeRecorder
{
    public static function enabled(): bool
    {
        return (bool) env('EDGE_RECORD', false);
    }

    public static function meta(string $screen, string $platform = 'web'): void
    {
        static::append([
            'kind' => 'meta',
            'screen' => $screen,
            'platform' => $platform,
            'app' => config('app.name'),
        ]);
    }

    public static function tree(array $tree, string $screen): void
    {
        static::append([
            'kind' => 'tree',
            'screen' => $screen,
            'payload' => $tree,
        ]);
    }

    public static function event(array $event, ?string $method = null): void
    {
        static::append([
            'kind' => 'event',
            'method' => $method,
            'payload' => $event,
        ]);
    }

    public static function nav(array $payload): void
    {
        static::append(['kind' => 'nav', 'payload' => $payload]);
    }

    // ── Internals ───────────────────────────────────

    protected static function append(array $frame): void
    {
        if (! static::enabled()) {
            return;
        }

        try {
            $path = static::path();
            $isNew = ! is_file($path);

            // Recordings are per-session; stamp a meta frame on first write
            // unless this frame IS the meta frame.
            if ($isNew && $frame['kind'] !== 'meta') {
                $head = json_encode([
                    't' => microtime(true),
                    'kind' => 'meta',
                    'screen' => $frame['screen'] ?? '/',
                    'platform' => static::onDevice()
                        ? (\Native\Mobile\Platform::current() ?? 'device')
                        : 'web',
                    'app' => config('app.name'),
                ]);
                file_put_contents($path, $head.PHP_EOL, FILE_APPEND | LOCK_EX);
            }

            $frame = ['t' => microtime(true)] + $frame;
            file_put_contents($path, json_encode($frame).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Recording must never break the app.
        }
    }

    /** Per-process stream id for device recordings (one runloop = one file). */
    protected static ?string $deviceStream = null;

    protected static function onDevice(): bool
    {
        return (bool) (env('NATIVEPHP_RUNNING') || config('nativephp-internal.running'));
    }

    protected static function path(): string
    {
        $dir = storage_path('app/edge-recordings');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (static::onDevice()) {
            static::$deviceStream ??= 'device'.date('His').getmypid();

            return $dir.'/'.static::$deviceStream.'.jsonl';
        }

        try {
            $id = session()->getId() ?: 'anon';
        } catch (\Throwable) {
            $id = 'anon';
        }

        return $dir.'/'.substr($id, 0, 16).'.jsonl';
    }

    /** @return array<int, array{name: string, frames: int, bytes: int, mtime: int}> */
    public static function recordings(): array
    {
        $dir = storage_path('app/edge-recordings');

        if (! is_dir($dir)) {
            return [];
        }

        $out = [];

        foreach (glob($dir.'/*.jsonl') as $file) {
            $out[] = [
                'name' => basename($file, '.jsonl'),
                'frames' => count(file($file)),
                'bytes' => filesize($file),
                'mtime' => filemtime($file),
            ];
        }

        usort($out, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $out;
    }

    /** @return array<int, array<string, mixed>> parsed frames, oldest first */
    public static function load(string $name): array
    {
        $path = storage_path('app/edge-recordings/'.basename($name).'.jsonl');

        abort_unless(is_file($path), 404);

        $frames = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $frames[] = $decoded;
            }
        }

        return $frames;
    }
}
