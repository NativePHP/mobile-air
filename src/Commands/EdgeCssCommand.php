<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Edge\Recording\ReplayViewer;
use Native\Mobile\Edge\Web\WebRenderer;
use Native\Mobile\Edge\Web\WebShell;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Build-time Tailwind stylesheet for the EDGE web render target.
 *
 * Replaces the Tailwind Play CDN (browser JIT) with a static sheet
 * compiled by the Tailwind v4 CLI. The input stylesheet declares:
 *
 *  - the app's theme palette as @theme tokens (same WebTheme::palette()
 *    block WebShell inlines for the CDN fallback), and
 *  - @source entries covering the app's native blade views plus the
 *    package's PHP renderers that emit classes (WebRenderer, WebShell,
 *    ReplayViewer).
 *
 * Two runtime transforms would otherwise make a static scan miss
 * classes, so a generated "candidates" file is scanned too — the view
 * sources with the SAME transforms WebRenderer::webClass() applies:
 *
 *  - `web:` variant prefixes stripped (`web:max-w-[560px]` is applied
 *    as `max-w-[560px]` on the web target), and
 *  - native unit brackets mapped to px (`text-[15]` → `text-[15px]`).
 *
 * Runtime-COMPUTED arbitrary values (`w-[{{ $battery }}%]`) can never
 * be scanned — those use the `style="width: {{ $battery }}%"` inline
 * passthrough instead (web_style prop).
 *
 * WebShell prefers public/vendor/edge/app.css when it exists and only
 * falls back to the CDN when it doesn't, so this command is optional in
 * dev and mandatory for CDN-free serving.
 */
class EdgeCssCommand extends Command
{
    protected $signature = 'edge:css';

    protected $description = 'Compile a static Tailwind stylesheet for the EDGE web render target (replaces the Play CDN)';

    public function handle(): int
    {
        $npx = (new ExecutableFinder)->find('npx');

        if ($npx === null) {
            $this->error('Node.js (npx) was not found on your PATH — it is required to compile the stylesheet.');
            $this->line('Install Node.js (e.g. `brew install node`, or via nvm), then re-run:');
            $this->line('  php artisan edge:css');

            return self::FAILURE;
        }

        $buildDir = base_path('.edge');
        if (! is_dir($buildDir) && ! mkdir($buildDir, 0755, true)) {
            $this->error("Could not create build directory: {$buildDir}");

            return self::FAILURE;
        }

        $viewsDir = resource_path('views/native');
        $viewFiles = $this->bladeFiles($viewsDir);

        if ($viewFiles === []) {
            $this->warn("No blade views found under {$viewsDir} — building from package renderers only.");
        }

        // Package renderer sources that emit class strings at runtime.
        $rendererFiles = array_filter([
            $this->classFile(WebRenderer::class),
            $this->classFile(WebShell::class),
            $this->classFile(ReplayViewer::class),
        ]);

        file_put_contents($buildDir.'/candidates.txt', $this->candidates($viewFiles));
        file_put_contents($buildDir.'/input.css', $this->inputCss($viewsDir, $rendererFiles, $buildDir));

        // The CLI resolves `@import "tailwindcss"` node-style from the
        // input file's directory — make sure the package exists somewhere
        // on that walk (apps typically declare it but ship no node_modules).
        if (! $this->ensureTailwindResolvable($buildDir)) {
            return self::FAILURE;
        }

        $out = public_path('vendor/edge/app.css');
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0755, true);
        }

        $this->line('Compiling with @tailwindcss/cli@4 …');

        $process = new Process(
            [$npx, '-y', '@tailwindcss/cli@4', '-i', $buildDir.'/input.css', '-o', $out, '--minify'],
            base_path(),
            null,
            null,
            300,
        );

        try {
            $process->run(fn ($type, $buffer) => $this->output->write($buffer));
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) {
            $this->error('Failed to run the Tailwind CLI: '.$e->getMessage());
            $this->line('Make sure Node.js and npm are installed and working (`npx --version`), then re-run:');
            $this->line('  php artisan edge:css');

            return self::FAILURE;
        }

        if (! $process->isSuccessful() || ! is_file($out)) {
            $this->error('Tailwind CLI exited with an error — the stylesheet was not (re)built.');

            return self::FAILURE;
        }

        $kb = round(filesize($out) / 1024, 1);
        $this->info("EDGE stylesheet built: public/vendor/edge/app.css ({$kb} KB)");
        $this->line('WebShell now serves this sheet instead of the Tailwind Play CDN. Re-run after view/theme changes.');

        return self::SUCCESS;
    }

    /**
     * `@import "tailwindcss"` must resolve from the build dir. When the
     * app has no node_modules yet, install tailwindcss@4 — into the app
     * root when a package.json exists (it's already a declared dev
     * dependency in NativePHP app skeletons, --no-save either way), or
     * contained inside .edge otherwise so npm can't climb to an
     * unrelated package.json further up the tree.
     */
    protected function ensureTailwindResolvable(string $buildDir): bool
    {
        if (is_file(base_path('node_modules/tailwindcss/package.json'))
            || is_file($buildDir.'/node_modules/tailwindcss/package.json')) {
            return true;
        }

        $npm = (new ExecutableFinder)->find('npm');

        if ($npm === null) {
            $this->error('npm was not found on your PATH — it is required to install the tailwindcss build dependency.');
            $this->line('Install Node.js (e.g. `brew install node`, or via nvm), then re-run:');
            $this->line('  php artisan edge:css');

            return false;
        }

        if (is_file(base_path('package.json'))) {
            $command = [$npm, 'install', '--no-save', '--no-audit', '--no-fund', 'tailwindcss@4'];
            $cwd = base_path();
        } else {
            if (! is_file($buildDir.'/package.json')) {
                file_put_contents($buildDir.'/package.json', "{}\n");
            }
            $command = [$npm, 'install', '--no-audit', '--no-fund', 'tailwindcss@4'];
            $cwd = $buildDir;
        }

        $this->line('Installing tailwindcss (build-time dependency) …');

        $process = new Process($command, $cwd, null, null, 300);

        try {
            $process->run(fn ($type, $buffer) => $this->output->write($buffer));
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) {
            $this->error('npm install failed to start: '.$e->getMessage());

            return false;
        }

        if (! $process->isSuccessful()) {
            $this->error('Could not install tailwindcss. Run `npm install` in your app, then re-run `php artisan edge:css`.');

            return false;
        }

        return true;
    }

    /** All *.blade.php under the app's native views dir, recursively. */
    protected function bladeFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** Real path of a class's source file (through the vendor symlink). */
    protected function classFile(string $class): ?string
    {
        $path = (new \ReflectionClass($class))->getFileName();

        return $path ? (realpath($path) ?: $path) : null;
    }

    /**
     * Scan fodder the raw sources can't provide: every view file's
     * content with the runtime class transforms applied (see class
     * docblock), plus a tiny safelist of renderer classes whose PHP
     * source form is escaped and therefore invisible to the scanner.
     */
    protected function candidates(array $viewFiles): string
    {
        $blob = "/* AUTO-GENERATED by `php artisan edge:css` — scan candidates, do not edit. */\n";

        // PHP-escaped in WebRenderer's switch track: 'after:content-[\'\']'.
        $blob .= "after:content-['']\n";

        foreach ($viewFiles as $file) {
            $blob .= $this->webify((string) file_get_contents($file))."\n";
        }

        return $blob;
    }

    /** The same transforms WebRenderer::webClass() applies at runtime. */
    protected function webify(string $src): string
    {
        // `web:` variant prefix → applied (stripped) on the web target.
        $src = preg_replace('/(^|[\s"\'])web:/m', '$1', $src);

        // Native unit brackets → px, e.g. text-[15] → text-[15px].
        return preg_replace_callback(
            '/([a-z][a-z0-9-]*)-\[(-?\d+(?:\.\d+)?)\]/',
            fn ($m) => in_array($m[1], WebRenderer::UNITLESS, true)
                ? $m[0]
                : "{$m[1]}-[{$m[2]}px]",
            $src,
        );
    }

    protected function inputCss(string $viewsDir, array $rendererFiles, string $buildDir): string
    {
        // source(none) disables Tailwind's automatic project scan — the
        // sheet is built from exactly these sources, deterministically.
        $css = "/* AUTO-GENERATED by `php artisan edge:css` — do not edit. */\n";
        $css .= "@import \"tailwindcss\" source(none);\n\n";

        if (is_dir($viewsDir)) {
            $css .= '@source "'.$viewsDir."\";\n";
        }
        foreach ($rendererFiles as $file) {
            $css .= '@source "'.$file."\";\n";
        }
        $css .= '@source "'.$buildDir."/candidates.txt\";\n\n";

        // Same @theme token block (light + dark override) WebShell inlines
        // for the CDN fallback — built sheet and fallback stay identical.
        $css .= WebShell::themeCss()."\n";

        return $css;
    }
}
