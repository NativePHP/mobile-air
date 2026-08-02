<?php

namespace Native\Mobile\Edge\Web\Renderer;

/**
 * Per-type HTML renderers for the EDGE wire tree — the web mirror of the
 * native renderer registries (SwiftUI/Compose keep theirs device-side).
 *
 * UI plugins own their element vocabulary on every render target, so a
 * plugin that registers an element type with ElementRegistry registers
 * its HTML rendering here rather than core's WebRenderer knowing every
 * plugin's types:
 *
 *     HtmlRendererRegistry::register('rating_stars',
 *         fn (array $node, array $ctx): string =>
 *             '<div'.WebRenderer::idAttr($node).' class="'.WebRenderer::cls($node, 'flex flex-row').'">'
 *                 .WebRenderer::children($node, $ctx)
 *             .'</div>'
 *     );
 *
 * A registration takes precedence over WebRenderer's built-in mapping,
 * so plugins can also override a core type's rendering wholesale.
 * Unknown, unregistered types keep the built-in fallback (children
 * render as a plain flex column; leaves render nothing).
 */
class HtmlRendererRegistry
{
    /** @var array<string, callable(array, array): string> */
    protected static array $renderers = [];

    /**
     * @param  callable(array $node, array $ctx): string  $renderer
     */
    public static function register(string $type, callable $renderer): void
    {
        static::$renderers[$type] = $renderer;
    }

    public static function has(string $type): bool
    {
        return isset(static::$renderers[$type]);
    }

    /** @return callable(array, array): string|null */
    public static function resolve(string $type): ?callable
    {
        return static::$renderers[$type] ?? null;
    }

    public static function all(): array
    {
        return static::$renderers;
    }

    public static function reset(): void
    {
        static::$renderers = [];
    }
}
