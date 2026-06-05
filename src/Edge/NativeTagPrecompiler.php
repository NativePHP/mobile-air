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

    /**
     * Bare tag names (without the `native:` prefix) that should also be
     * recognized as native elements. Populated by the service provider
     * from `ElementRegistry::all()` (types converted snake_case →
     * kebab-case) so both `<native:column>` and `<column>` compile to
     * the same NativeElementCollector calls.
     *
     * @var string[]
     */
    private array $shortFormTags;

    /** Precomputed alternation regex group (e.g. `column|row|stack|...`). */
    private ?string $shortFormAlt;

    /**
     * @param  string[]  $shortFormTags  Bare tag names to recognize alongside `<native:*>`.
     */
    public function __construct(array $shortFormTags = [])
    {
        $this->shortFormTags = $shortFormTags;

        // Sort by descending length so the regex alternation matches the
        // longest tag first — otherwise `<top-bar-action>` would tokenize
        // as `<top-bar>` with `-action` left over as attrs when both
        // names are in the list.
        $sorted = $shortFormTags;
        usort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        $this->shortFormAlt = $sorted === []
            ? null
            : implode('|', array_map('preg_quote', $sorted));
    }

    public function __invoke(string $value): string
    {
        // Expand `native:model="propName"` (with optional Livewire-style
        // modifiers) into the equivalent `:value` + `_change` + `sync-mode`
        // attribute set. Supported shapes:
        //
        //     native:model="name"                 — default live, echo-prevention
        //     native:model.live="name"            — explicit live
        //     native:model.blur="name"            — dispatch only on focus loss
        //     native:model.lazy="name"            — alias for .blur
        //     native:model.debounce.300ms="name"  — dispatch after Nms of inactivity
        //
        // This is the native counterpart to Livewire's `wire:model`. The two
        // address different rendering paths (native tree vs. WebView DOM) and
        // are not meant to be mixed on a single element.
        $value = preg_replace_callback(
            '/native:model(\.[a-zA-Z0-9.]+)?=["\']([^"\']+)["\']/',
            fn ($m) => $this->compileNativeModel($m[2], $m[1] ?? ''),
            $value
        );

        // Legacy shorthand — `@model="propName"` expands to the live variant.
        // Kept for backwards compatibility; prefer `native:model` going forward.
        $value = preg_replace_callback(
            '/@model=["\']([^"\']+)["\']/',
            fn ($m) => ':value="$'.$m[1].'" _change="__syncProperty(\''.$m[1].'\')" sync-mode="live"',
            $value
        );

        // Expand `native:poll` into a `native-poll="<ms>"` attribute. The
        // attribute parser doesn't accept ':' in names, so the directive
        // is normalized here (compile-time) to a plain hyphenated attr the
        // collector reads to register a frame-level re-render timer:
        //
        //     native:poll              — default 2s
        //     native:poll="1s"         — value form (1s / 500ms / 1500)
        //     native:poll.2s           — Livewire-style modifier form
        //
        // Re-rendering is whole-screen (like Livewire's wire:poll), so the
        // value is the cadence at which this screen re-renders; any live
        // expression inside (e.g. {{ now() }}) refreshes on each tick.
        $value = preg_replace_callback(
            '/native:poll\b(?:\.([a-zA-Z0-9.]+))?(?:=["\']([^"\']*)["\'])?/',
            fn ($m) => 'native-poll="'.self::pollMsFromSpec(
                ($m[2] ?? '') !== '' ? $m[2] : ($m[1] ?? '')
            ).'"',
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

        // The attribute-region pattern below uses possessive quantifiers
        // (`*+`) to keep PCRE from catastrophically backtracking when a
        // long template has many tags and quoted attribute values. The
        // earlier non-possessive form hit PHP's pcre.backtrack_limit on
        // templates above ~9KB, returning NULL silently — caller saw
        // "No root element was built" because tags weren't transformed.
        //
        // The character class also excludes `/` so the trailing `/>`
        // terminator of a self-closing tag stays visible to the closing
        // pattern. Without that exclusion the possessive `*+` would
        // swallow the `/` and the regex would fail to match (where the
        // non-possessive form previously backtracked to release it).
        $attrs = "((?:[^>\"'\\/]*+(?:\"[^\"]*+\"|'[^']*+')[^>\"'\\/]*+)*+|[^>\"'\\/]*+)";

        // 1. Self-closing tags: <native:type attrs />
        $value = preg_replace_callback(
            '/<\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*'.$attrs.'\s*\/>/s',
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
            '/<\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*'.$attrs.'\s*>/s',
            fn ($m) => $this->compileOpening($m[1], trim($m[2] ?? '')),
            $value
        );

        // Short-form pass — same compilation, no `native:` prefix required.
        // Tag must be in the registered-element allowlist so we don't
        // accidentally rewrite arbitrary markup. Order matches the
        // prefixed pass: self-closing, then closing, then opening.
        if ($this->shortFormAlt !== null) {
            $alt = $this->shortFormAlt;

            $value = preg_replace_callback(
                '/<\s*('.$alt.')\s*'.$attrs.'\s*\/>/s',
                fn ($m) => $this->compileSelfClosing($m[1], trim($m[2] ?? '')),
                $value
            );

            $value = preg_replace_callback(
                '/<\/\s*('.$alt.')\s*>/s',
                fn ($m) => $this->compileClosing($m[1]),
                $value
            );

            $value = preg_replace_callback(
                '/<\s*('.$alt.')\s*'.$attrs.'\s*>/s',
                fn ($m) => $this->compileOpening($m[1], trim($m[2] ?? '')),
                $value
            );
        }

        return $value;
    }

    private function tagToType(string $tag): string
    {
        return str_replace('-', '_', $tag);
    }

    /**
     * Expand a `native:model` directive into the equivalent attribute triplet.
     *
     *   $prop       — the property name (as written in the Blade attribute)
     *   $modifiers  — the leading-dot chain (e.g. ".live", ".debounce.300ms"),
     *                 or empty string when no modifier was supplied
     *
     * Output format is a Blade attribute string (no surrounding whitespace).
     */
    private function compileNativeModel(string $prop, string $modifiers): string
    {
        $syncMode = 'live';
        $debounceMs = 0;

        if ($modifiers !== '') {
            $parts = explode('.', trim($modifiers, '.'));
            $head = $parts[0] ?? '';

            if ($head === 'blur' || $head === 'lazy') {
                $syncMode = 'blur';
            } elseif ($head === 'debounce') {
                $syncMode = 'debounce';
                // Accept `.debounce.300ms` — if the ms segment is missing or
                // malformed, fall back to a sensible 300ms default so typos
                // don't silently flip modes.
                if (isset($parts[1]) && preg_match('/^(\d+)ms$/', $parts[1], $m)) {
                    $debounceMs = (int) $m[1];
                } else {
                    $debounceMs = 300;
                }
            }
            // `.live` or anything unknown falls through to syncMode=live.
        }

        $out = ':value="$'.$prop.'" _change="__syncProperty(\''.$prop.'\')" sync-mode="'.$syncMode.'"';
        if ($debounceMs > 0) {
            $out .= ' debounce-ms="'.$debounceMs.'"';
        }

        return $out;
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

        // <native:virtual-list /> is special: open the element, loop the
        // window, render the `item` Blade view once per index (each render
        // streams its own native tags into the same collector), then close.
        // Lets the user write a single self-closing tag while we silently
        // open/iterate/close behind the scenes — keeps the DX symmetric
        // with `<native:list>` even though semantically this is a
        // container element.
        if ($type === 'virtual_list') {
            return $this->compileVirtualList($attrs);
        }

        return '<?php '.self::C."::leaf('{$type}', {$attrs}); ?>";
    }

    private function compileVirtualList(string $attrs): string
    {
        $C = self::C;

        return "<?php \$__vlAttrs = {$attrs};
            \$__vlItem = \$__vlAttrs['item'] ?? null;
            unset(\$__vlAttrs['item']);
            \$__vlFrom = (int)(\$__vlAttrs['from'] ?? \$__vlAttrs['window_from'] ?? \$__vlAttrs['windowFrom'] ?? 0);
            \$__vlTo = (int)(\$__vlAttrs['to'] ?? \$__vlAttrs['window_to'] ?? \$__vlAttrs['windowTo'] ?? \$__vlFrom + 29);
            \$__vlCount = (int)(\$__vlAttrs['count'] ?? 0);
            {$C}::open('virtual_list', \$__vlAttrs);
            if (\$__vlItem && \$__vlCount > 0) {
                \$__vlEnd = min(\$__vlTo, \$__vlCount - 1);
                for (\$__vlI = max(0, \$__vlFrom); \$__vlI <= \$__vlEnd; \$__vlI++) {
                    view(\$__vlItem, ['index' => \$__vlI])->render();
                }
            }
            {$C}::close(); ?>";
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

    /**
     * Parse a `native:poll` duration spec into milliseconds.
     *   ''      → 2000 (default 2s)
     *   '500ms' → 500
     *   '1s' / '1.5s' → 1000 / 1500
     *   '750'   → 750 (bare number = ms, matching #[Poll(ms)])
     */
    private static function pollMsFromSpec(string $spec): int
    {
        $spec = trim($spec);

        if ($spec === '') {
            return 2000;
        }
        if (str_ends_with($spec, 'ms')) {
            return max(1, (int) round((float) substr($spec, 0, -2)));
        }
        if (str_ends_with($spec, 's')) {
            return max(1, (int) round((float) substr($spec, 0, -1) * 1000));
        }

        return max(1, (int) round((float) $spec));
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