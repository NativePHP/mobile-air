<?php

namespace Native\Mobile\Edge\Web\Protocol;

use Native\Mobile\Edge\Web\Renderer\WebTheme;

/**
 * Full-page HTML shell for the web render target (POC).
 *
 * Ships the Tailwind v4 browser build (JIT in the browser — the whole
 * point of the raw-class passthrough), the theme token palette as
 * --color-theme-* variables so `bg-theme-primary` etc. resolve, Material
 * Symbols for <native:icon>, and the edge.js client runtime inline.
 */
class WebShell
{
    public static function page(string $html, array $state, string $title = 'App'): string
    {
        // Raw JSON inside <script type="application/json"> — entities are
        // NOT decoded there, so escape via \uXXXX (JSON_HEX_TAG guards
        // against `</script>` breakout) instead of htmlspecialchars.
        $stateJson = json_encode([
            'endpoint' => EdgeEndpoint::updatePath(),
            'uploadEndpoint' => EdgeEndpoint::uploadPath(),
            ...$state,
            'csrf' => csrf_token(),
        ], JSON_HEX_TAG | JSON_HEX_AMP);

        $runtime = file_get_contents(__DIR__.'/../../../../resources/js/edge-web.js');
        $tailwind = static::tailwindHead();
        $fonts = static::fontCss();
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        // App-wide default face (Theme fonts.default), same as native.
        $defaultFont = isset(WebTheme::fontAliases()['default'])
            ? WebTheme::resolveFont('default')
            : null;
        $bodyStyle = $defaultFont !== null
            ? ' style="font-family:\''.htmlspecialchars($defaultFont, ENT_QUOTES, 'UTF-8').'\', system-ui, sans-serif"'
            : '';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleEsc}</title>
{$tailwind}
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<style>
{$fonts}
</style>
<style>
html, body { height: 100%; }
#edge-root { height: 100%; position: relative; }
.edge-stack > * { grid-area: 1 / 1; }
.material-symbols-outlined {
  font-family: 'Material Symbols Outlined';
  font-weight: normal; font-style: normal; line-height: 1;
  letter-spacing: normal; text-transform: none; display: inline-block;
  white-space: nowrap; word-wrap: normal; direction: ltr;
  -webkit-font-smoothing: antialiased; user-select: none;
}
</style>
</head>
<body class="bg-theme-background text-theme-on-surface antialiased"{$bodyStyle}>
<div id="edge-root">{$html}</div>
<script type="application/json" id="edge-state">{$stateJson}</script>
<script>
{$runtime}
</script>
</body>
</html>
HTML;
    }

    /**
     * The Tailwind side of the <head>: the build-time stylesheet
     * (`php artisan edge:css` → public/vendor/edge/app.css) when it
     * exists — it already contains the compiled utilities AND the @theme
     * token palette, so the CDN script and the inline @theme block are
     * omitted. Falls back to the Tailwind browser CDN + inline @theme so
     * dev-without-a-build keeps working exactly as before.
     */
    protected static function tailwindHead(): string
    {
        $built = public_path('vendor/edge/app.css');

        if (is_file($built)) {
            // filemtime cache-buster: redeploys/rebuilds change the URL.
            return '<link rel="stylesheet" href="/vendor/edge/app.css?v='.filemtime($built).'">';
        }

        $theme = static::themeCss();

        return "<script src=\"https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4\"></script>\n"
            ."<style type=\"text/tailwindcss\">\n{$theme}\n</style>";
    }

    /**
     * @font-face rules for every file in the app's resources/fonts — the
     * same files the native build bundles. Family name = file basename,
     * which is exactly what `font="..."` tokens (and Theme aliases)
     * resolve to, so the native font vocabulary works unchanged.
     */
    protected static function fontCss(): string
    {
        $css = '';

        foreach (WebTheme::fontFiles() as $file) {
            $family = preg_replace('/\.(ttf|otf|woff2?)$/i', '', $file);
            $url = EdgeEndpoint::fontUrl($file);
            $format = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                'otf' => 'opentype',
                'woff' => 'woff',
                'woff2' => 'woff2',
                default => 'truetype',
            };

            $css .= "@font-face { font-family: '".addslashes($family)."'; src: url('{$url}') format('{$format}'); font-display: swap; }\n";
        }

        return $css;
    }

    /**
     * The bg-theme-* / text-theme-* palette as Tailwind v4 @theme tokens.
     * Uses the app's mobile-ui Theme when resolvable; otherwise a
     * Material-ish default so screens are legible out of the box.
     *
     * Public: `edge:css` embeds the same block in its build input so the
     * static sheet carries identical tokens to the CDN fallback.
     */
    public static function themeCss(): string
    {
        [$light, $dark] = WebTheme::palette();

        $themeVars = '';
        foreach ($light as $token => $hex) {
            $themeVars .= "  --color-theme-{$token}: {$hex};\n";
        }

        $darkVars = '';
        foreach ($dark as $token => $hex) {
            $darkVars .= "    --color-theme-{$token}: {$hex};\n";
        }

        return <<<CSS
@theme {
{$themeVars}}

@media (prefers-color-scheme: dark) {
  :root {
{$darkVars}  }
}
CSS;
    }
}
