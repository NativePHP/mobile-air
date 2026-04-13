<?php

namespace Native\Mobile\Edge;

/**
 * Compiles <native:*> tags directly into NativeElementCollector calls,
 * bypassing the Blade component lifecycle (IoC, class instantiation,
 * render(), sub-view resolution) for significantly faster rendering.
 */
class NativeTagPrecompiler
{
    /**
     * Elements that capture their slot content as a text prop.
     * tag name => prop name for the captured text
     */
    private const TEXT_ELEMENTS = [
        'text' => 'text',
        'button' => 'label',
    ];

    /**
     * Edge navigation components — handled via Edge::add()/startContext()/endContext()
     * instead of NativeElementCollector, so Edge.Set bridge calls work in WebView mode.
     */
    private const EDGE_CONTAINER_TAGS = [
        'top-bar', 'bottom-nav', 'side-nav', 'side-nav-group',
    ];

    private const EDGE_LEAF_TAGS = [
        'top-bar-action', 'bottom-nav-item', 'side-nav-item', 'side-nav-header',
    ];

    /** camelCase modifier → Transition enum value */
    private const NAVIGATE_TRANSITIONS = [
        'fade' => 'fade',
        'slideFromRight' => 'slide_from_right',
        'slideFromLeft' => 'slide_from_left',
        'slideFromBottom' => 'slide_from_bottom',
        'fadeFromBottom' => 'fade_from_bottom',
        'scaleFromCenter' => 'scale_from_center',
        'none' => 'none',
    ];

    private const C = '\\Native\\Mobile\\Edge\\NativeElementCollector';

    public function __invoke(string $value): string
    {
        // Expand @model="propName" into :value="$propName" _change="__syncProperty('propName')"
        $value = preg_replace_callback(
            '/@model=["\']([^"\']+)["\']/',
            fn ($m) => ':value="$'.$m[1].'" _change="__syncProperty(\''.$m[1].'\')"',
            $value
        );

        // Expand @navigate directives into :_navigate dynamic attribute
        // Short style:  @navigate.fade='/route'  or  @navigate='/route'
        // Paren style:  @navigate.fade('/route', ['data' => 'val'])
        // Quote style:  @navigate.fade="'/route', ['data' => 'val']"
        // Boolean style: @navigate.back
        $value = preg_replace_callback(
            '/@navigate\b(?:\.([\w.]+))?(?:=\'([^\']*)\'|="([^"]*)"|(\((?:[^()]*|\([^()]*\))*\)))?/',
            fn ($m) => $this->compileNavigateDirective(
                $m[1] ?? '',
                ! empty($m[4]) ? substr($m[4], 1, -1) : (($m[2] ?? '') !== '' ? "'{$m[2]}'" : ($m[3] ?? '')),
            ),
            $value
        );

        // Convert @press, @longPress, @change, @submit, @dismiss, @refresh,
        // @endReached, @swipeDelete to underscored versions before Blade interprets @ as a directive
        $value = preg_replace('/@(press|longPress|change|submit|dismiss|refresh|endReached|swipeDelete)=/', '_$1=', $value);

        // 1. Self-closing tags: <native:type attrs />
        $value = preg_replace_callback(
            '/<\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*((?:[^>"\']*(?:"[^"]*"|\'[^\']*\')[^>"\']*)*|[^>"\']*?)\s*\/>/s',
            fn ($m) => $this->compileSelfClosing($m[1], trim($m[2] ?? '')),
            $value
        );

        // 2. Closing tags: </native:type>
        $value = preg_replace_callback(
            '/<\/\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*>/s',
            fn ($m) => $this->compileClosing($m[1]),
            $value
        );

        // 3. Opening tags: <native:type attrs>
        $value = preg_replace_callback(
            '/<\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*((?:[^>"\']*(?:"[^"]*"|\'[^\']*\')[^>"\']*)*|[^>"\']*?)\s*>/s',
            fn ($m) => $this->compileOpening($m[1], trim($m[2] ?? '')),
            $value
        );

        return $value;
    }

    private function tagToType(string $tag): string
    {
        return str_replace('-', '_', $tag);
    }

    private function compileSelfClosing(string $tag, string $rawAttrs): string
    {
        if (in_array($tag, self::EDGE_LEAF_TAGS, true)) {
            $type = $this->tagToType($tag);
            $attrs = $this->compileAttributes($rawAttrs);

            return '<?php \\Native\\Mobile\\Edge\\Edge::add(\''.$type.'\', '.$attrs.'); ?>';
        }

        $type = $this->tagToType($tag);
        $attrs = $this->compileAttributes($rawAttrs);

        return '<?php '.self::C."::leaf('{$type}', {$attrs}); ?>";
    }

    private function compileOpening(string $tag, string $rawAttrs): string
    {
        if (in_array($tag, self::EDGE_CONTAINER_TAGS, true)) {
            $attrs = $this->compileAttributes($rawAttrs);
            $varTag = str_replace('-', '_', $tag);

            return "<?php \$__edgeCtx_{$varTag} = \\Native\\Mobile\\Edge\\Edge::startContext(); \$__edgeAttrs_{$varTag} = {$attrs}; ?>";
        }

        if (in_array($tag, self::EDGE_LEAF_TAGS, true)) {
            $type = $this->tagToType($tag);
            $attrs = $this->compileAttributes($rawAttrs);

            return '<?php \\Native\\Mobile\\Edge\\Edge::add(\''.$type.'\', '.$attrs.'); ?>';
        }

        $type = $this->tagToType($tag);
        $attrs = $this->compileAttributes($rawAttrs);

        // Text-capture elements: save attrs and start output buffering
        if (isset(self::TEXT_ELEMENTS[$tag])) {
            return "<?php \$__nativeSlotAttrs = {$attrs}; ob_start(); ?>";
        }

        // Container: push onto collector stack
        return '<?php '.self::C."::open('{$type}', {$attrs}); ?>";
    }

    private function compileClosing(string $tag): string
    {
        if (in_array($tag, self::EDGE_CONTAINER_TAGS, true)) {
            $type = $this->tagToType($tag);
            $varTag = str_replace('-', '_', $tag);

            return "<?php \\Native\\Mobile\\Edge\\Edge::endContext(\$__edgeCtx_{$varTag}, '{$type}', \$__edgeAttrs_{$varTag}); ?>";
        }

        if (in_array($tag, self::EDGE_LEAF_TAGS, true)) {
            return ''; // Leaf tags don't have closing tags in practice, but handle gracefully
        }

        if (isset(self::TEXT_ELEMENTS[$tag])) {
            $propName = self::TEXT_ELEMENTS[$tag];
            $type = $this->tagToType($tag);

            $code = '<?php $__nativeSlot = preg_replace(\'/\s+/\', \' \', trim(html_entity_decode(strip_tags(ob_get_clean()), ENT_QUOTES, \'UTF-8\')));';

            if ($tag === 'button') {
                $code .= " if (\$__nativeSlot !== '' && !isset(\$__nativeSlotAttrs['label'])) { \$__nativeSlotAttrs['label'] = \$__nativeSlot; }";
            } else {
                $code .= " if (\$__nativeSlot !== '') { \$__nativeSlotAttrs['{$propName}'] = \$__nativeSlot; }";
            }

            $code .= ' '.self::C."::leaf('{$type}', \$__nativeSlotAttrs); ?>";

            return $code;
        }

        // Container: pop from collector stack
        return '<?php '.self::C.'::close(); ?>';
    }

    /**
     * Compile an attribute value, interpolating Blade {{ }} and {!! !!} syntax.
     *
     * In native context we skip e() since there's no HTML to escape.
     *   "{{ $category }}"          → ($category)
     *   "{!! $raw !!}"             → ($raw)
     *   "Price: {{ $price }}/night" → 'Price: ' . ($price) . '/night'
     *   "plain text"                → 'plain text'
     */
    private function compileAttributeValue(string $value): string
    {
        // No Blade interpolation — return as literal string
        if (! preg_match('/\{\{|\{!!/', $value)) {
            return "'".addslashes($value)."'";
        }

        // Split on {{ expr }} and {!! expr !!} boundaries, keeping delimiters
        $parts = preg_split('/(\{\{.*?\}\}|\{!!.*?!!\})/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $segments = [];

        foreach ($parts as $part) {
            if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/s', $part, $m)) {
                $segments[] = '('.trim($m[1]).')';
            } elseif (preg_match('/^\{!!\s*(.+?)\s*!!\}$/s', $part, $m)) {
                $segments[] = '('.trim($m[1]).')';
            } else {
                $segments[] = "'".addslashes($part)."'";
            }
        }

        return count($segments) === 1 ? $segments[0] : implode(' . ', $segments);
    }

    private function compileNavigateDirective(string $modifiers, string $args): string
    {
        $parts = $modifiers !== '' ? explode('.', $modifiers) : [];

        $type = 'navigate';
        $transition = 'null';

        foreach ($parts as $part) {
            if (isset(self::NAVIGATE_TRANSITIONS[$part])) {
                $transition = "'".self::NAVIGATE_TRANSITIONS[$part]."'";
            } elseif (in_array($part, ['replace', 'exitToWeb', 'back'], true)) {
                $type = $part;
            }
        }

        $args = trim($args);
        $nav = '\\'.self::class.'::nav';

        if ($args === '') {
            return ":_navigate=\"{$nav}('{$type}', {$transition})\"";
        }

        return ":_navigate=\"{$nav}('{$type}', {$transition}, {$args})\"";
    }

    /**
     * Runtime helper called from compiled templates to build navigation config.
     */
    public static function nav(string $type, ?string $transition, string $uri = '', array $data = []): array
    {
        return compact('type', 'transition', 'uri', 'data');
    }

    private function compileAttributes(string $rawAttrs): string
    {
        if ($rawAttrs === '') {
            return '[]';
        }

        $parts = [];
        $remaining = $rawAttrs;

        while (($remaining = ltrim($remaining)) !== '') {
            // Dynamic attribute :name="expr"
            if (preg_match('/^:([a-zA-Z0-9_\-]+)\s*=\s*"([^"]*)"/s', $remaining, $m)) {
                $parts[] = "'".addslashes($m[1])."' => (".$m[2].')';
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Dynamic attribute :name='expr'
            if (preg_match("/^:([a-zA-Z0-9_\\-]+)\\s*=\\s*'([^']*)'/s", $remaining, $m)) {
                $parts[] = "'".addslashes($m[1])."' => (".$m[2].')';
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Static attribute name="value"
            if (preg_match('/^([a-zA-Z0-9_\-]+)\s*=\s*"([^"]*)"/s', $remaining, $m)) {
                $parts[] = "'".addslashes($m[1])."' => ".$this->compileAttributeValue($m[2]);
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Static attribute name='value'
            if (preg_match("/^([a-zA-Z0-9_\\-]+)\\s*=\\s*'([^']*)'/s", $remaining, $m)) {
                $parts[] = "'".addslashes($m[1])."' => ".$this->compileAttributeValue($m[2]);
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Boolean attribute (standalone word)
            if (preg_match('/^([a-zA-Z0-9_\-]+)/', $remaining, $m)) {
                $parts[] = "'".$m[1]."' => true";
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Skip unrecognized character
            $remaining = substr($remaining, 1);
        }

        return '['.implode(', ', $parts).']';
    }
}