<?php

namespace Native\Mobile\Support;

use Composer\InstalledVersions;

/**
 * Remembers which build preparation steps ran successfully, and with what
 * inputs, so the next build can skip the ones whose inputs are unchanged.
 *
 * The state lives in a small JSON file inside the generated platform
 * project (nativephp/ios, nativephp/android). A step's fingerprint is only
 * written after the step succeeds, and is forgotten before it runs, so a
 * build that dies halfway through never leaves a step marked as done.
 *
 * Everything is discarded when the context changes: a different
 * nativephp/mobile version, a different build type (debug and release
 * differ in --no-dev), or a new state file schema.
 */
class BuildState
{
    public const FILE = '.nativephp-build-state.json';

    private const SCHEMA = 1;

    /** @var array<string, string> */
    private array $steps = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly string $path,
        private readonly array $context,
        bool $fresh = false,
    ) {
        if ($fresh || ! is_file($path)) {
            return;
        }

        $data = json_decode((string) @file_get_contents($path), true);

        if (! is_array($data) || ($data['context'] ?? null) !== $context || ! is_array($data['steps'] ?? null)) {
            return;
        }

        $this->steps = array_filter($data['steps'], 'is_string');
    }

    /**
     * Load the state for a platform project directory.
     */
    public static function for(string $platformPath, string $buildType, bool $fresh = false): self
    {
        return new self(
            rtrim($platformPath, '/\\').DIRECTORY_SEPARATOR.self::FILE,
            self::context($buildType),
            $fresh,
        );
    }

    /**
     * Forget everything recorded for a platform project.
     */
    public static function clear(string $platformPath): void
    {
        $file = rtrim($platformPath, '/\\').DIRECTORY_SEPARATOR.self::FILE;

        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function context(string $buildType): array
    {
        return [
            'schema' => self::SCHEMA,
            'package' => self::packageVersion(),
            'build' => $buildType,
        ];
    }

    /**
     * Whether a step already ran successfully with exactly these inputs.
     * A null fingerprint means the inputs could not be pinned down, so the
     * step always runs.
     */
    public function matches(string $step, ?string $fingerprint): bool
    {
        return $fingerprint !== null && ($this->steps[$step] ?? null) === $fingerprint;
    }

    public function has(string $step): bool
    {
        return isset($this->steps[$step]);
    }

    public function forget(string ...$steps): void
    {
        $changed = false;

        foreach ($steps as $step) {
            if (isset($this->steps[$step])) {
                unset($this->steps[$step]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->save();
        }
    }

    public function record(string $step, ?string $fingerprint): void
    {
        if ($fingerprint === null) {
            $this->forget($step);

            return;
        }

        $this->steps[$step] = $fingerprint;
        $this->save();
    }

    public function path(): string
    {
        return $this->path;
    }

    private function save(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            return;
        }

        $json = json_encode([
            'context' => $this->context,
            'steps' => $this->steps,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Write then rename, so an interrupted write can't leave a
        // half-written file that later parses as something else.
        $temp = $this->path.'.tmp';

        if (@file_put_contents($temp, $json) === false || ! @rename($temp, $this->path)) {
            @unlink($temp);
            @unlink($this->path);
        }
    }

    private static function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('nativephp/mobile')
                .'@'.InstalledVersions::getReference('nativephp/mobile');
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
