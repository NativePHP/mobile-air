<?php

namespace Native\Mobile\Edge\Web;

/**
 * POC HTML renderer for the EDGE element tree ("React Native Web" mode).
 *
 * Walks the wire tree from Element::toArray() and emits HTML with the
 * author's raw Tailwind classes passed through (props.web_class — real
 * Tailwind runs in the browser). Interactivity is wired through
 * data-edge-* attributes that edge-web.js turns into /_edge/update posts
 * carrying the native callback ids.
 *
 * Philosophy mirrors the native renderer registries: known types get a
 * mapping; unknown types with children render as a plain flex column;
 * unknown leaves (mobile-only concepts) render nothing.
 */
class WebRenderer
{
    /** Utility prefixes whose bracket-values are unitless in real Tailwind. */
    public const UNITLESS = [
        'z', 'opacity', 'flex', 'scale', 'scale-x', 'scale-y', 'leading',
        'order', 'grow', 'shrink', 'columns', 'line-clamp', 'rotate',
        'aspect', 'font',
    ];

    /**
     * When rendering the `?_platform=mobile` dev preview, `web:` variant
     * classes are dropped (as they would be on device) instead of applied.
     */
    protected static bool $mobilePreview = false;

    public static function setMobilePreview(bool $on): void
    {
        static::$mobilePreview = $on;
    }

    public static function render(array $node, array $ctx = []): string
    {
        if (($node['flags'] ?? 0) === 1) {
            return ''; // REUSE marker — never emitted here (throwaway hashes)
        }

        $type = $node['type'] ?? '';
        $p = $node['props'] ?? [];

        return match ($type) {
            'column' => static::container($node, 'div', 'flex flex-col', $ctx),
            'row' => static::container($node, 'div', 'flex flex-row items-center', $ctx),
            'stack' => static::container($node, 'div', 'edge-stack grid', $ctx),
            'scroll_view' => static::scrollView($node, $p, $ctx),
            'spacer' => static::selfClosing($node, 'flex-1 self-stretch'),
            'divider', 'horizontal_divider' => '<hr class="self-stretch w-full border-theme-outline-variant">',
            'text' => static::text($node, $p, $ctx),
            'image' => static::image($node, $p),
            'icon' => static::icon($node, $p),
            'pressable' => static::container($node, 'button', 'flex flex-col appearance-none text-left cursor-pointer', $ctx, ' type="button"'),
            'button' => static::button($node, $p),
            'outlined_text_input', 'filled_text_input', 'bare_text_input', 'text_input' => static::textInput($node, $p, $type),
            'toggle' => static::toggle($node, $p),
            'checkbox' => static::checkbox($node, $p),
            'slider' => static::slider($node, $p),
            'select' => static::select($node, $p),
            'date_picker' => static::datePicker($node, $p),
            'radio_group' => static::radioGroup($node, $p, $ctx),
            'radio' => static::radio($node, $p, $ctx),
            'badge' => static::badge($node, $p),
            'chip' => static::chip($node, $p),
            'list' => static::list($node, $p, $ctx),
            'list_section' => static::listSection($node, $p, $ctx),
            'list_item' => static::listItem($node, $p),
            // Windowing stays server-driven (window_from/window_to props render
            // only the live slice). The marker + scroll container are the seam
            // for the JS follow-up.
            // TODO(edge-web.js): observe scroll on [data-edge-virtual] and
            // request window shifts (count/overscan/estimated_row_height props).
            'virtual_list' => static::container($node, 'div', 'flex flex-col w-full overflow-y-auto', $ctx, ' data-edge-virtual'),
            'lazy_grid' => static::lazyGrid($node, $p, $ctx),
            'tab_row' => static::tabRow($node, $p, $ctx),
            'tab' => static::tab($node, $p, $ctx),
            'progress_bar' => static::progressBar($node, $p),
            'activity_indicator' => static::activityIndicator($node, $p),
            'modal' => static::overlay($node, $p, $ctx, sheet: false),
            'bottom_sheet' => static::overlay($node, $p, $ctx, sheet: true),
            'carousel' => static::container($node, 'div', 'flex flex-row overflow-x-auto snap-x snap-mandatory gap-4 w-full', $ctx),
            'button_group' => static::buttonGroup($node, $p),
            'webview' => static::webview($node, $p),
            'top_bar' => static::topBar($node, $p, $ctx),
            'top_bar_title' => static::container($node, 'div', 'flex flex-row items-center gap-2 flex-1', $ctx),
            'top_bar_action' => static::topBarAction($node, $p),
            'bottom_nav' => static::container($node, 'nav', 'flex flex-row justify-around items-stretch w-full border-t border-theme-outline-variant bg-theme-surface mt-auto shrink-0', $ctx),
            'bottom_nav_item' => static::navItem($node, $p, bottom: true),
            'side_nav' => static::container($node, 'aside', 'hidden md:flex flex-col w-64 shrink-0 border-r border-theme-outline-variant bg-theme-surface overflow-y-auto', $ctx),
            'side_nav_item' => static::navItem($node, $p, bottom: false),
            'side_nav_group' => static::sideNavGroup($node, $p, $ctx),
            'side_nav_header' => static::sideNavHeader($node, $p),
            'bottom_bar' => static::container($node, 'div', 'flex flex-row items-center gap-2 w-full border-t border-theme-outline-variant bg-theme-surface p-2 mt-auto shrink-0', $ctx),
            'refreshable', 'gesture_area', 'canvas' => static::container($node, 'div', 'flex flex-col', $ctx),
            'native_root_stack', 'native_root_tabs' => static::chromeRoot($node, $p, $ctx),
            'floating_overlay' => static::floatingOverlay($node, $p, $ctx),
            'native_drawer' => static::children($node, $ctx),
            'rect', 'circle', 'line' => '', // canvas primitives — skipped on web (POC)
            'activity', 'fab' => static::container($node, 'button', 'cursor-pointer', $ctx, ' type="button"'),
            default => static::unknown($node, $ctx),
        };
    }

    // ── Generic builders ────────────────────────────

    protected static function container(array $node, string $tag, string $base, array $ctx, string $extra = ''): string
    {
        $isButton = $tag === 'button';
        $attrs = static::idAttr($node).static::pressAttrs($node, $isButton).static::styleAttr($node).$extra;

        return "<{$tag}{$attrs} class=\"".static::cls($node, $base).'">'
            .static::children($node, $ctx)
            ."</{$tag}>";
    }

    /**
     * Combined inline-style attribute for generic containers/leaves:
     * absolute-position CSS (FABs, canvas overlays) plus the author's raw
     * `style="..."` passthrough (web_style prop). The latter exists for
     * runtime-computed values — `style="width: {{ $battery }}%"` — that
     * the build-time class scan (edge:css) can never see in source, so
     * dynamic arbitrary classes like `w-[{{ $battery }}%]` aren't needed.
     */
    protected static function styleAttr(array $node): string
    {
        // absoluteCss() segments each end in ';', so plain concat is safe.
        $css = static::absoluteCss($node).trim((string) ($node['props']['web_style'] ?? ''));

        return $css === '' ? '' : ' style="'.static::e($css).'"';
    }

    /**
     * Absolutely-positioned nodes (FABs, canvas overlays): translate the
     * native layout position + size + core style fields to inline CSS.
     * Only for position_type=1 nodes — everywhere else classes rule, and
     * inline colors would break dark-mode variants.
     */
    protected static function absoluteCss(array $node): string
    {
        $layout = $node['layout'] ?? [];

        if (($layout['position_type'] ?? 0) !== 1) {
            return '';
        }

        $css = 'position:absolute;z-index:10;display:flex;align-items:center;justify-content:center;';

        [$top, $right, $bottom, $left] = ($layout['position'] ?? [0, 0, 0, 0]) + [0, 0, 0, 0];
        foreach (['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left] as $side => $v) {
            if ((float) $v != 0) {
                $css .= "{$side}:{$v}px;";
            }
        }

        foreach (['width', 'height'] as $dim) {
            if (isset($layout[$dim]) && is_numeric($layout[$dim])) {
                $css .= "{$dim}:{$layout[$dim]}px;";
            }
        }

        $style = $node['style'] ?? [];
        if (isset($style['bg_color'])) {
            $css .= 'background-color:'.static::cssColor($style['bg_color']).';';
        }
        if (isset($style['border_radius'])) {
            $css .= 'border-radius:'.((float) $style['border_radius']).'px;';
        }

        return $css;
    }

    protected static function selfClosing(array $node, string $base): string
    {
        return '<div'.static::idAttr($node).static::styleAttr($node).' class="'.static::cls($node, $base).'"></div>';
    }

    protected static function children(array $node, array $ctx): string
    {
        $html = '';

        foreach ($node['children'] ?? [] as $child) {
            $html .= static::render($child, $ctx);
        }

        return $html;
    }

    protected static function unknown(array $node, array $ctx): string
    {
        // Mirror native registries: unknown containers fall back to a flex
        // column; unknown leaves (mobile-only) render nothing.
        if (! empty($node['children'])) {
            return static::container($node, 'div', 'flex flex-col', $ctx);
        }

        return '';
    }

    // ── Core content ────────────────────────────────

    protected static function text(array $node, array $p, array $ctx): string
    {
        $attrs = static::idAttr($node).static::pressAttrs($node, false).static::fontAttr($p);
        $class = static::cls($node, '');

        // Inline runs: a <text> with children composes ordered spans.
        $inner = static::e($p['text'] ?? '');
        foreach ($node['children'] ?? [] as $run) {
            $inner .= static::text($run, $run['props'] ?? [], $ctx);
        }

        return "<span{$attrs} class=\"{$class}\">{$inner}</span>";
    }

    /** `font="..."` token (alias or file basename) → inline font-family. */
    protected static function fontAttr(array $p, string $key = 'font_name'): string
    {
        $family = WebTheme::resolveFont($p[$key] ?? null);

        return $family !== null
            ? ' style="font-family:\''.static::e($family).'\'"'
            : '';
    }

    protected static function image(array $node, array $p): string
    {
        $src = static::e($p['src'] ?? '');
        // alt falls back to the a11y label so icon-only/imagery pressables
        // keep an accessible name even without an explicit alt prop.
        $alt = static::e($p['alt'] ?? $p['a11y_label'] ?? '');

        // Author-supplied object-* class wins over the cover default.
        $base = str_contains($p['web_class'] ?? '', 'object-')
            ? 'max-w-full'
            : 'object-cover max-w-full';

        return '<img'.static::idAttr($node).static::pressAttrs($node, false)
            ." src=\"{$src}\" alt=\"{$alt}\" class=\"".static::cls($node, $base).'">';
    }

    protected static function icon(array $node, array $p, string $extraClass = ''): string
    {
        $style = '';

        // Labeled icons (a11y_label → aria-label via idAttr) are real
        // content — role="img" instead of aria-hidden, so icon-only
        // pressables stay announceable.
        $aria = ($p['a11y_label'] ?? '') !== '' ? ' role="img"' : ' aria-hidden="true"';

        // Inline the resolved color ONLY when no text-* class is present:
        // the parsed prop is the LIGHT-mode hex, and inlining it would
        // override the theme class's dark-mode variant in the browser.
        $hasColorClass = str_contains($p['web_class'] ?? '', 'text-');
        if (! $hasColorClass && isset($p['color']) && is_string($p['color']) && str_starts_with($p['color'], '#')) {
            $style .= 'color:'.static::cssColor($p['color']).';';
        }

        // `web="arrow-up"` names a heroicon — the web-native icon set,
        // preferred over the Material-name fallback when present.
        if (! empty($p['web_icon']) && ($svg = static::heroicon($p['web_icon'])) !== null) {
            $size = (float) ($p['size'] ?? 24);
            $style .= "width:{$size}px;height:{$size}px;";

            return '<span'.static::idAttr($node).static::pressAttrs($node, false)
                .' class="inline-block select-none [&>svg]:w-full [&>svg]:h-full '.$extraClass.' '.static::webClass($node).'"'
                ." style=\"{$style}\"{$aria}>".$svg.'</span>';
        }

        $name = $p['name'] ?? null;

        if (! $name) {
            return '';
        }

        if (isset($p['size'])) {
            $style .= 'font-size:'.((float) $p['size']).'px;';
        }

        return '<span'.static::idAttr($node).static::pressAttrs($node, false)
            .' class="material-symbols-outlined select-none '.$extraClass.' '.static::webClass($node).'"'
            .($style !== '' ? " style=\"{$style}\"" : '')
            .$aria.'>'.static::e(static::materialName($name)).'</span>';
    }

    /**
     * Inline a heroicon SVG from blade-ui-kit/blade-heroicons if the app
     * has it installed. Accepts "arrow-up" (outline default) or a
     * variant-prefixed "s-arrow-up" / "m-arrow-up" / "c-arrow-up".
     */
    protected static function heroicon(string $name): ?string
    {
        static $dir = null;
        static $cache = [];

        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }

        $dir ??= base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg');

        $file = preg_match('/^[osmc]-/', $name) ? $name : "o-{$name}";
        $path = $dir.'/'.basename($file).'.svg';

        return $cache[$name] = is_file($path) ? file_get_contents($path) : null;
    }

    protected static function scrollView(array $node, array $p, array $ctx): string
    {
        $axis = $p['axis'] ?? (($p['horizontal'] ?? false) ? 'horizontal' : 'vertical');

        $base = match ($axis) {
            'horizontal' => 'flex flex-row overflow-x-auto min-w-0',
            'both' => 'overflow-auto min-h-0 min-w-0',
            default => 'flex flex-col overflow-y-auto min-h-0',
        };

        return static::container($node, 'div', $base, $ctx);
    }

    // ── Inputs & controls ───────────────────────────

    protected static function button(array $node, array $p): string
    {
        $variant = $p['variant'] ?? 'primary';
        $size = $p['size'] ?? 'md';
        $disabled = ! empty($p['disabled']) || ! empty($p['loading']);

        $variantCls = match ($variant) {
            'secondary' => 'bg-theme-secondary text-theme-on-secondary',
            'destructive' => 'bg-theme-destructive text-theme-on-destructive',
            'success' => 'bg-theme-success text-theme-on-success',
            'ghost' => 'bg-transparent text-theme-primary',
            default => 'bg-theme-primary text-theme-on-primary',
        };

        $sizeCls = match ($size) {
            'sm' => 'px-3 py-1.5 text-sm rounded-lg',
            'lg' => 'px-6 py-3 text-lg rounded-xl',
            default => 'px-4 py-2.5 rounded-lg',
        };

        $inner = '';
        if (! empty($p['loading'])) {
            $inner .= '<span class="inline-block w-4 h-4 rounded-full border-2 border-current border-t-transparent animate-spin"></span>';
        } elseif (! empty($p['leading_icon'])) {
            $inner .= '<span class="material-symbols-outlined text-[20px] leading-none">'.static::e(static::materialName($p['leading_icon'])).'</span>';
        }
        $inner .= static::e($p['label'] ?? '');
        if (! empty($p['trailing_icon'])) {
            $inner .= '<span class="material-symbols-outlined text-[20px] leading-none">'.static::e(static::materialName($p['trailing_icon'])).'</span>';
        }

        return '<button type="button"'.static::idAttr($node).static::pressAttrs($node, true).static::fontAttr($p)
            .($disabled ? ' disabled' : '')
            .' class="inline-flex items-center justify-center gap-2 font-medium cursor-pointer select-none '
            .'disabled:opacity-50 disabled:cursor-default active:opacity-80 '
            .$variantCls.' '.$sizeCls.' '.static::webClass($node).'">'
            .$inner.'</button>';
    }

    protected static function textInput(array $node, array $p, string $type): string
    {
        $variantCls = match ($type) {
            'filled_text_input' => 'bg-theme-surface-variant rounded-t-lg border-b-2 border-theme-outline px-3 py-2.5',
            'bare_text_input' => 'bg-transparent',
            'text_input' => 'border border-theme-outline rounded-lg px-3 py-2.5 bg-transparent',
            default => 'border border-theme-outline rounded-lg px-3 py-2.5 bg-transparent',
        };

        if (! empty($p['is_error'])) {
            $variantCls .= ' border-theme-destructive';
        }

        $inputType = match (true) {
            ! empty($p['secure']) => 'password',
            ($p['keyboard'] ?? $p['keyboard_type'] ?? '') === 'email' => 'email',
            ($p['keyboard'] ?? $p['keyboard_type'] ?? '') === 'number',
            ($p['keyboard'] ?? $p['keyboard_type'] ?? '') === 'decimal' => 'number',
            ($p['keyboard'] ?? $p['keyboard_type'] ?? '') === 'phone' => 'tel',
            ($p['keyboard'] ?? $p['keyboard_type'] ?? '') === 'url' => 'url',
            default => 'text',
        };

        $bindings = static::idAttr($node);
        if (! empty($p['on_change'])) {
            $bindings .= ' data-edge-change="'.((int) $p['on_change']).'"'
                .' data-edge-sync="'.static::e($p['sync_mode'] ?? 'live').'"'
                .(isset($p['debounce_ms']) ? ' data-edge-debounce="'.((int) $p['debounce_ms']).'"' : '');
        }
        if (! empty($p['on_submit'])) {
            $bindings .= ' data-edge-submit="'.((int) $p['on_submit']).'"';
        }

        $common = $bindings.static::fontAttr($p)
            .' placeholder="'.static::e($p['placeholder'] ?? '').'"'
            .(! empty($p['disabled']) ? ' disabled' : '')
            .(! empty($p['read_only']) ? ' readonly' : '')
            .(isset($p['max_length']) ? ' maxlength="'.((int) $p['max_length']).'"' : '');

        // Decorations (BaseTextInput): prefix/suffix text + leading/trailing
        // icons; `loading` replaces the trailing icon with a spinner.
        $lead = '';
        if (! empty($p['leading_icon'])) {
            $lead .= '<span class="material-symbols-outlined text-[20px] leading-none text-theme-on-surface-variant select-none" aria-hidden="true">'.static::e(static::materialName($p['leading_icon'])).'</span>';
        }
        if (isset($p['prefix']) && $p['prefix'] !== '') {
            $lead .= '<span class="text-theme-on-surface-variant select-none">'.static::e($p['prefix']).'</span>';
        }
        $trail = '';
        if (isset($p['suffix']) && $p['suffix'] !== '') {
            $trail .= '<span class="text-theme-on-surface-variant select-none">'.static::e($p['suffix']).'</span>';
        }
        if (! empty($p['loading'])) {
            $trail .= '<span class="inline-block w-4 h-4 rounded-full border-2 border-current border-t-transparent animate-spin text-theme-on-surface-variant"></span>';
        } elseif (! empty($p['trailing_icon'])) {
            $trail .= '<span class="material-symbols-outlined text-[20px] leading-none text-theme-on-surface-variant select-none" aria-hidden="true">'.static::e(static::materialName($p['trailing_icon'])).'</span>';
        }

        $inputCls = 'w-full outline-none focus:border-theme-primary text-theme-on-surface placeholder:text-theme-on-surface-variant '.$variantCls;

        if (! empty($p['multiline'])) {
            $rows = (int) ($p['min_lines'] ?? 3);
            $field = "<textarea{$common} rows=\"{$rows}\" class=\"{$inputCls} resize-y\">".static::e($p['value'] ?? '').'</textarea>';
        } elseif ($lead !== '' || $trail !== '') {
            // Decorated field: the variant chrome moves to a wrapper row so
            // the decorations sit inside the field's border/background.
            $field = '<span class="flex flex-row items-center gap-2 focus-within:border-theme-primary '.$variantCls.'">'
                .$lead
                ."<input type=\"{$inputType}\"{$common} value=\"".static::e($p['value'] ?? '').'" class="flex-1 min-w-0 outline-none bg-transparent text-theme-on-surface placeholder:text-theme-on-surface-variant">'
                .$trail.'</span>';
        } else {
            $field = "<input type=\"{$inputType}\"{$common} value=\"".static::e($p['value'] ?? '')."\" class=\"{$inputCls}\">";
        }

        $label = isset($p['label']) && $p['label'] !== ''
            ? '<span class="text-sm font-medium '.(! empty($p['is_error']) ? 'text-theme-destructive' : 'text-theme-on-surface-variant').'">'.static::e($p['label']).'</span>'
            : '';
        $supporting = isset($p['supporting']) && $p['supporting'] !== ''
            ? '<span class="text-xs '.(! empty($p['is_error']) ? 'text-theme-destructive' : 'text-theme-on-surface-variant').'">'.static::e($p['supporting']).'</span>'
            : '';

        return '<label class="flex flex-col gap-1 '.(! empty($p['disabled']) ? 'opacity-60 ' : '').static::webClass($node).'">'
            .$label.$field.$supporting.'</label>';
    }

    protected static function toggle(array $node, array $p): string
    {
        return static::switchControl($node, $p, 'data-edge-toggle', $p['on_change'] ?? 0);
    }

    protected static function checkbox(array $node, array $p): string
    {
        $input = '<input type="checkbox"'.static::idAttr($node)
            .(! empty($p['on_change']) ? ' data-edge-checkbox="'.((int) $p['on_change']).'"' : '')
            .(! empty($p['value']) ? ' checked' : '')
            .(! empty($p['disabled']) ? ' disabled' : '')
            .' class="w-5 h-5 accent-theme-primary">';

        return '<label class="inline-flex items-center gap-3 cursor-pointer '.(! empty($p['disabled']) ? 'opacity-60 cursor-default ' : '').static::webClass($node).'">'
            .$input
            .(isset($p['label']) ? '<span>'.static::e($p['label']).'</span>' : '')
            .'</label>';
    }

    protected static function switchControl(array $node, array $p, string $dataAttr, int $cbId): string
    {
        $checked = ! empty($p['value']);

        $input = '<input type="checkbox" role="switch"'.static::idAttr($node)
            .($cbId ? " {$dataAttr}=\"{$cbId}\"" : '')
            .($checked ? ' checked' : '')
            .(! empty($p['disabled']) ? ' disabled' : '')
            .' class="peer sr-only">';

        $track = '<span class="w-11 h-6 rounded-full bg-theme-outline-variant peer-checked:bg-theme-primary transition-colors relative '
            .'after:content-[\'\'] after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5 after:rounded-full after:bg-white after:shadow '
            .'after:transition-transform peer-checked:after:translate-x-5"></span>';

        return '<label class="inline-flex items-center gap-3 cursor-pointer '.(! empty($p['disabled']) ? 'opacity-60 cursor-default ' : '').static::webClass($node).'">'
            .$input.$track
            .(isset($p['label']) && $p['label'] !== '' ? '<span>'.static::e($p['label']).'</span>' : '')
            .'</label>';
    }

    protected static function slider(array $node, array $p): string
    {
        return '<input type="range"'.static::idAttr($node)
            .(! empty($p['on_change']) ? ' data-edge-slider="'.((int) $p['on_change']).'"' : '')
            .' min="'.((float) ($p['min'] ?? 0)).'" max="'.((float) ($p['max'] ?? 1)).'"'
            .(isset($p['step']) && $p['step'] > 0 ? ' step="'.((float) $p['step']).'"' : ' step="any"')
            .' value="'.((float) ($p['value'] ?? 0)).'"'
            .(! empty($p['disabled']) ? ' disabled' : '')
            .' class="accent-theme-primary w-full disabled:opacity-50 '.static::webClass($node).'">';
    }

    protected static function select(array $node, array $p): string
    {
        $value = (string) ($p['value'] ?? '');
        $opts = array_map('strval', $p['options'] ?? []);

        // Placeholder edge cases: render it when explicitly provided, when
        // no value is set, or when the value matches no option — without
        // the last case the browser silently highlights the first option.
        $hasMatch = $value !== '' && in_array($value, $opts, true);

        $options = '';
        if (! $hasMatch || isset($p['placeholder'])) {
            $options .= '<option value="" disabled'.(! $hasMatch ? ' selected' : '').' hidden>'
                .static::e($p['placeholder'] ?? 'Select…').'</option>';
        }
        foreach ($opts as $opt) {
            $options .= '<option value="'.static::e($opt).'"'.($opt === $value ? ' selected' : '').'>'.static::e($opt).'</option>';
        }

        $field = '<select'.static::idAttr($node)
            .(! empty($p['on_change']) ? ' data-edge-select="'.((int) $p['on_change']).'"' : '')
            .(! empty($p['disabled']) ? ' disabled' : '')
            .' class="border border-theme-outline rounded-lg px-3 py-2.5 bg-theme-surface text-theme-on-surface w-full outline-none focus:border-theme-primary disabled:opacity-50">'
            .$options.'</select>';

        $label = isset($p['label']) && $p['label'] !== ''
            ? '<span class="text-sm font-medium text-theme-on-surface-variant">'.static::e($p['label']).'</span>'
            : '';

        return '<label class="flex flex-col gap-1 '.static::webClass($node).'">'.$label.$field.'</label>';
    }

    protected static function datePicker(array $node, array $p): string
    {
        $inputType = match ($p['mode'] ?? 'date') {
            'time' => 'time',
            'datetime' => 'datetime-local',
            default => 'date',
        };

        $field = '<input type="'.$inputType.'"'.static::idAttr($node)
            .(! empty($p['on_change']) ? ' data-edge-date="'.((int) $p['on_change']).'"' : '')
            .' value="'.static::e($p['value'] ?? '').'"'
            .(isset($p['min']) ? ' min="'.static::e($p['min']).'"' : '')
            .(isset($p['max']) ? ' max="'.static::e($p['max']).'"' : '')
            .(! empty($p['disabled']) ? ' disabled' : '')
            .' class="border border-theme-outline rounded-lg px-3 py-2 bg-theme-surface text-theme-on-surface outline-none focus:border-theme-primary disabled:opacity-50">';

        $label = isset($p['label']) && $p['label'] !== ''
            ? '<span class="text-sm font-medium text-theme-on-surface-variant">'.static::e($p['label']).'</span>'
            : '';

        return '<label class="flex flex-col gap-1 '.static::webClass($node).'">'.$label.$field.'</label>';
    }

    protected static function radioGroup(array $node, array $p, array $ctx): string
    {
        $ctx['radio'] = [
            'name' => 'edge-rg-'.($node['id'] ?? 0),
            'cb' => (int) ($p['on_change'] ?? 0),
            'value' => (string) ($p['value'] ?? ''),
        ];

        $label = isset($p['label']) && $p['label'] !== ''
            ? '<span class="text-sm font-medium text-theme-on-surface-variant">'.static::e($p['label']).'</span>'
            : '';

        // A disabled <fieldset> natively disables every radio inside it —
        // the group-level `disabled` prop needs no per-child plumbing.
        return '<fieldset'.static::idAttr($node)
            .(! empty($p['disabled']) ? ' disabled' : '')
            .' class="flex flex-col gap-2 '.(! empty($p['disabled']) ? 'opacity-60 ' : '').static::webClass($node).'">'
            .$label.static::children($node, $ctx).'</fieldset>';
    }

    protected static function radio(array $node, array $p, array $ctx): string
    {
        $group = $ctx['radio'] ?? ['name' => 'edge-rg', 'cb' => 0, 'value' => ''];
        $value = (string) ($p['value'] ?? '');

        $input = '<input type="radio"'.static::idAttr($node)
            .' name="'.static::e($group['name']).'" value="'.static::e($value).'"'
            .($group['cb'] ? ' data-edge-radio="'.$group['cb'].'"' : '')
            .($value !== '' && $value === $group['value'] ? ' checked' : '')
            .(! empty($p['disabled']) ? ' disabled' : '')
            .' class="w-5 h-5 accent-theme-primary">';

        return '<label class="inline-flex items-center gap-3 cursor-pointer '.(! empty($p['disabled']) ? 'opacity-60 cursor-default ' : '').static::webClass($node).'">'
            .$input
            .(isset($p['label']) ? '<span>'.static::e($p['label']).'</span>' : '')
            .'</label>';
    }

    protected static function badge(array $node, array $p): string
    {
        $content = isset($p['count']) ? (string) $p['count'] : ($p['label'] ?? '');

        return '<span'.static::idAttr($node)
            .' class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs font-medium bg-theme-destructive text-theme-on-destructive '
            .static::webClass($node).'">'.static::e($content).'</span>';
    }

    protected static function chip(array $node, array $p): string
    {
        $selected = ! empty($p['value']);

        $stateCls = $selected
            ? 'bg-theme-primary/15 border-theme-primary text-theme-primary'
            : 'border-theme-outline text-theme-on-surface';

        $icon = ! empty($p['icon'])
            ? '<span class="material-symbols-outlined text-[18px] leading-none">'.static::e(static::materialName($p['icon'])).'</span>'
            : '';

        return '<button type="button"'.static::idAttr($node)
            .(! empty($p['on_change']) ? ' data-edge-chip="'.((int) $p['on_change']).'" data-edge-checked="'.($selected ? '1' : '0').'" aria-pressed="'.($selected ? 'true' : 'false').'"' : '')
            .(! empty($p['disabled']) ? ' disabled' : '')
            .' class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm cursor-pointer select-none disabled:opacity-50 disabled:cursor-default '
            .$stateCls.' '.static::webClass($node).'">'
            .$icon.static::e($p['label'] ?? '').'</button>';
    }

    // ── Lists ───────────────────────────────────────

    protected static function list(array $node, array $p, array $ctx): string
    {
        $base = ! empty($p['horizontal'])
            ? 'flex flex-row overflow-x-auto w-full'
            : 'flex flex-col w-full overflow-y-auto min-h-0';

        if (($p['separator'] ?? true) && empty($p['horizontal'])) {
            $base .= ' divide-y divide-theme-outline-variant';
        }

        return static::container($node, 'div', $base, $ctx);
    }

    protected static function listSection(array $node, array $p, array $ctx): string
    {
        $header = isset($p['header']) && $p['header'] !== ''
            ? '<div class="px-4 pt-4 pb-1 text-sm font-medium text-theme-on-surface-variant">'.static::e($p['header']).'</div>'
            : '';
        $footer = isset($p['footer']) && $p['footer'] !== ''
            ? '<div class="px-4 pt-1 pb-3 text-xs text-theme-on-surface-variant">'.static::e($p['footer']).'</div>'
            : '';

        return '<div'.static::idAttr($node).' class="flex flex-col w-full '.static::webClass($node).'">'
            .$header.static::children($node, $ctx).$footer.'</div>';
    }

    protected static function listItem(array $node, array $p): string
    {
        // Leading slot
        $leading = '';
        $lv = $p['leading_value'] ?? null;
        switch ($p['leading_type'] ?? null) {
            case 'icon':
                // `web="..."` heroicon wins; Material-name fallback otherwise.
                if (! empty($p['web_icon']) && ($svg = static::heroicon($p['web_icon'])) !== null) {
                    $glyph = '<span class="inline-block w-5 h-5 [&>svg]:w-full [&>svg]:h-full">'.$svg.'</span>';
                } else {
                    $glyph = '<span class="material-symbols-outlined text-[22px] leading-none">'.static::e(static::materialName($p['leading_icon'] ?? $lv ?? '')).'</span>';
                }

                if (! empty($p['leading_icon_bg_color'])) {
                    $leading = '<span class="w-10 h-10 rounded-xl shrink-0 inline-flex items-center justify-center text-white"'
                        .' style="background-color:'.static::cssColor($p['leading_icon_bg_color']).'">'.$glyph.'</span>';
                } else {
                    $leading = '<span class="text-theme-on-surface-variant inline-flex items-center">'.$glyph.'</span>';
                }
                break;
            case 'avatar':
                $leading = '<img src="'.static::e($lv ?? '').'" alt="" class="w-10 h-10 rounded-full object-cover">';
                break;
            case 'monogram':
                $bg = isset($p['leading_monogram_color']) ? ' style="background-color:'.static::cssColor($p['leading_monogram_color']).'"' : '';
                $leading = '<span class="w-10 h-10 rounded-full bg-theme-primary/20 text-theme-primary inline-flex items-center justify-center font-medium"'.$bg.'>'.static::e($lv ?? '').'</span>';
                break;
            case 'image':
                $leading = '<img src="'.static::e($lv ?? '').'" alt="" class="w-14 h-14 rounded-lg object-cover">';
                break;
            case 'checkbox':
            case 'radio':
                $leading = '<input type="checkbox"'
                    .(! empty($p['on_leading_change']) ? ' data-edge-checkbox="'.((int) $p['on_leading_change']).'"' : '')
                    .(! empty($p['leading_checked']) ? ' checked' : '')
                    .' class="w-5 h-5 accent-theme-primary">';
                break;
        }

        // Text column
        $textCol = '<span class="flex flex-col flex-1 min-w-0">';
        if (isset($p['overline']) && $p['overline'] !== '') {
            $textCol .= '<span class="text-xs uppercase tracking-wide text-theme-on-surface-variant">'.static::e($p['overline']).'</span>';
        }
        $textCol .= '<span class="text-theme-on-surface truncate">'.static::e($p['headline'] ?? '').'</span>';
        if (isset($p['supporting']) && $p['supporting'] !== '') {
            $textCol .= '<span class="text-sm text-theme-on-surface-variant truncate">'.static::e($p['supporting']).'</span>';
        }
        $textCol .= '</span>';

        // Trailing slot
        $trailing = '';
        $tv = $p['trailing_value'] ?? null;
        switch ($p['trailing_type'] ?? null) {
            case 'icon':
                $trailing = '<span class="material-symbols-outlined text-theme-on-surface-variant">'.static::e(static::materialName($p['trailing_icon'] ?? $tv ?? '')).'</span>';
                break;
            case 'text':
                $trailing = '<span class="text-sm text-theme-on-surface-variant">'.static::e($tv ?? '').'</span>';
                break;
            case 'switch':
                $trailing = static::switchControl(['id' => ($node['id'] ?? 0).'-tsw'], [
                    'value' => ! empty($p['trailing_checked']),
                ], 'data-edge-toggle', (int) ($p['on_trailing_change'] ?? 0));
                break;
            case 'checkbox':
                $trailing = '<input type="checkbox"'
                    .(! empty($p['on_trailing_change']) ? ' data-edge-checkbox="'.((int) $p['on_trailing_change']).'"' : '')
                    .(! empty($p['trailing_checked']) ? ' checked' : '')
                    .' class="w-5 h-5 accent-theme-primary">';
                break;
            case 'icon_button':
                $trailing = '<button type="button"'
                    .(! empty($p['on_trailing_press']) ? ' data-edge-press="'.((int) $p['on_trailing_press']).'"' : '')
                    .' class="material-symbols-outlined text-theme-on-surface-variant cursor-pointer p-1">'
                    .static::e(static::materialName($p['trailing_icon'] ?? $tv ?? 'more_vert')).'</button>';
                break;
        }

        $tag = isset($node['on_press']) ? 'button' : 'div';
        $extra = $tag === 'button' ? ' type="button"' : '';

        return "<{$tag}{$extra}".static::idAttr($node).static::pressAttrs($node, $tag === 'button')
            .' class="flex flex-row items-center gap-4 px-4 py-3 w-full text-left'
            .($tag === 'button' ? ' cursor-pointer hover:bg-theme-surface-variant/50' : '')
            .' '.static::webClass($node).'">'
            .$leading.$textCol.$trailing."</{$tag}>";
    }

    protected static function lazyGrid(array $node, array $p, array $ctx): string
    {
        $cols = max(1, (int) ($p['columns'] ?? 2));
        $gap = (float) ($p['gap'] ?? 8);

        return '<div'.static::idAttr($node)
            .' style="display:grid;grid-template-columns:repeat('.$cols.',minmax(0,1fr));gap:'.$gap.'px"'
            .' class="w-full '.static::webClass($node).'">'
            .static::children($node, $ctx).'</div>';
    }

    // ── Tabs / segmented ────────────────────────────

    protected static function tabRow(array $node, array $p, array $ctx): string
    {
        $ctx['tabs'] = [
            'cb' => (int) ($p['on_change'] ?? 0),
            'selected' => (int) ($p['value'] ?? 0),
            'index' => 0,
        ];

        $html = '<div'.static::idAttr($node).' class="flex flex-row w-full border-b border-theme-outline-variant '.static::webClass($node).'">';

        foreach ($node['children'] ?? [] as $child) {
            $html .= static::render($child, $ctx);
            $ctx['tabs']['index']++;
        }

        return $html.'</div>';
    }

    protected static function tab(array $node, array $p, array $ctx): string
    {
        $tabs = $ctx['tabs'] ?? ['cb' => 0, 'selected' => -1, 'index' => 0];
        $index = $tabs['index'];
        $active = $index === $tabs['selected'];

        $stateCls = $active
            ? 'border-b-2 border-theme-primary text-theme-primary font-medium'
            : 'border-b-2 border-transparent text-theme-on-surface-variant';

        $icon = ! empty($p['icon'])
            ? '<span class="material-symbols-outlined text-[20px] leading-none">'.static::e(static::materialName($p['icon'])).'</span>'
            : '';

        return '<button type="button"'.static::idAttr($node)
            .($tabs['cb'] ? ' data-edge-tab="'.$tabs['cb'].'" data-edge-value="'.$index.'"' : '')
            .' class="flex-1 inline-flex items-center justify-center gap-1.5 px-4 py-3 text-sm cursor-pointer '
            .$stateCls.' '.static::webClass($node).'">'
            .$icon.static::e($p['label'] ?? '').'</button>';
    }

    protected static function buttonGroup(array $node, array $p): string
    {
        $selected = (int) ($p['value'] ?? 0);
        $cbId = (int) ($p['on_change'] ?? 0);

        $html = '<div'.static::idAttr($node)
            .' class="inline-flex rounded-lg border border-theme-outline overflow-hidden divide-x divide-theme-outline '.static::webClass($node).'">';

        foreach ($p['options'] ?? [] as $i => $opt) {
            $active = $i === $selected;
            $html .= '<button type="button"'
                .($cbId ? ' data-edge-tab="'.$cbId.'" data-edge-value="'.$i.'"' : '')
                .' aria-pressed="'.($active ? 'true' : 'false').'"'
                .(! empty($p['disabled']) ? ' disabled' : '')
                .' class="px-4 py-2 text-sm cursor-pointer disabled:opacity-50 disabled:cursor-default '
                .($active ? 'bg-theme-primary text-theme-on-primary' : 'bg-theme-surface text-theme-on-surface')
                .'">'.static::e((string) $opt).'</button>';
        }

        return $html.'</div>';
    }

    // ── Progress / status ───────────────────────────

    protected static function progressBar(array $node, array $p): string
    {
        $color = isset($p['color']) ? 'background-color:'.static::cssColor($p['color']) : '';

        if (! empty($p['indeterminate'])) {
            $inner = '<div class="h-full w-1/3 rounded-full bg-theme-primary animate-pulse" style="'.$color.'"></div>';
        } else {
            $pct = max(0, min(100, ((float) ($p['value'] ?? 0)) * 100));
            $inner = '<div class="h-full rounded-full bg-theme-primary transition-all" style="width:'.$pct.'%;'.$color.'"></div>';
        }

        return '<div'.static::idAttr($node)
            .' class="w-full h-1.5 rounded-full bg-theme-surface-variant overflow-hidden '.static::webClass($node).'">'
            .$inner.'</div>';
    }

    protected static function activityIndicator(array $node, array $p): string
    {
        $sizeCls = match ($p['size'] ?? 'md') {
            'sm' => 'w-4 h-4 border-2',
            'lg' => 'w-8 h-8 border-[3px]',
            default => 'w-6 h-6 border-2',
        };

        // Core variant carries a float size instead.
        if (is_numeric($p['size'] ?? null)) {
            $px = (float) $p['size'];
            $sizeCls = 'border-2';
            $style = ' style="width:'.$px.'px;height:'.$px.'px"';
        } else {
            $style = '';
        }

        return '<span'.static::idAttr($node).$style
            .' class="inline-block rounded-full border-theme-outline-variant border-t-theme-primary animate-spin '
            .$sizeCls.' '.static::webClass($node).'"></span>';
    }

    // ── Overlays ────────────────────────────────────

    protected static function overlay(array $node, array $p, array $ctx, bool $sheet): string
    {
        if (empty($p['visible'])) {
            return '';
        }

        $dismiss = ! empty($p['on_dismiss']) && ($p['dismissible'] ?? true)
            ? ' data-edge-dismiss="'.((int) $p['on_dismiss']).'"'
            : '';

        if ($sheet) {
            $panel = '<div class="absolute inset-x-0 bottom-0 max-h-[85%] overflow-y-auto rounded-t-2xl bg-theme-surface shadow-2xl p-4 flex flex-col '.static::webClass($node).'">'
                .'<div class="self-center w-10 h-1 rounded-full bg-theme-outline-variant mb-3"></div>'
                .static::children($node, $ctx).'</div>';
        } else {
            $panel = '<div class="relative m-auto max-w-lg w-[90%] max-h-[85%] overflow-y-auto rounded-2xl bg-theme-surface shadow-2xl p-6 flex flex-col '.static::webClass($node).'">'
                .static::children($node, $ctx).'</div>';
        }

        return '<div'.static::idAttr($node).$dismiss
            .' class="fixed inset-0 z-50 bg-black/40 flex">'
            .$panel.'</div>';
    }

    protected static function floatingOverlay(array $node, array $p, array $ctx): string
    {
        $align = ($p['alignment'] ?? 'bottom') === 'top' ? 'top-4' : 'bottom-4';
        $offset = (int) ($p['offset'] ?? 0);

        return '<div'.static::idAttr($node)
            .' class="fixed inset-x-0 '.$align.' z-40 flex justify-center pointer-events-none '.static::webClass($node).'"'
            .($offset ? ' style="margin-bottom:'.$offset.'px"' : '').'>'
            .'<div class="pointer-events-auto">'.static::children($node, $ctx).'</div></div>';
    }

    // ── Chrome ──────────────────────────────────────

    protected static function chromeRoot(array $node, array $p, array $ctx): string
    {
        $title = $p['nav_title'] ?? $p['title'] ?? '';
        $back = ! empty($p['nav_back']) || ! empty($p['back']);
        $hideNav = ! empty($p['hide_nav_bar']);

        // Partition sentinel children (see NativeRootTabs / NativeRootStack):
        // `bottom_nav_item` nodes are the tab bar, `top_bar_action` nodes are
        // the folded NavBar actions, `search_item` nodes are the native-side
        // search corpus (never page content). Everything else is the active
        // screen's content.
        $tabs = [];
        $actionsHtml = '';
        $contentHtml = '';
        foreach ($node['children'] ?? [] as $child) {
            switch ($child['type'] ?? '') {
                case 'bottom_nav_item':
                    $tabs[] = $child;
                    break;
                case 'top_bar_action':
                    $actionsHtml .= static::render($child, $ctx);
                    break;
                case 'search_item':
                    break;
                default:
                    $contentHtml .= static::render($child, $ctx);
            }
        }

        // Inline NavBar search (search_placeholder / nav_search_placeholder):
        // styled placeholder only — tapping it does nothing yet.
        // TODO(edge-web): functional search — wire an input to the
        // `search_on_query` / `nav_search_on_query` callback (kind
        // `search_query`) and render the returned items as a result list.
        $searchPlaceholder = trim((string) ($p['nav_search_placeholder'] ?? $p['search_placeholder'] ?? ''));
        $search = '';
        if (! $hideNav && $searchPlaceholder !== '') {
            $search = '<div data-edge-search-placeholder class="flex flex-row items-center gap-2 mx-3 mb-2 px-3 py-2 rounded-full bg-theme-surface-variant text-theme-on-surface-variant text-sm select-none">'
                .'<span class="material-symbols-outlined text-[20px] leading-none" aria-hidden="true">search</span>'
                .'<span>'.static::e($searchPlaceholder).'</span></div>';
        }

        $header = '';
        if (! $hideNav && ($title !== '' || $back || $actionsHtml !== '' || $search !== '')) {
            $navFont = static::fontAttr(['font_name' => $p['nav_font_name'] ?? $p['font_name'] ?? null]);
            $header = '<header class="flex flex-col bg-theme-surface border-b border-theme-outline-variant shrink-0 sticky top-0 z-10">'
                .'<div class="flex flex-row items-center gap-2 px-2 py-2.5">'
                .($back ? '<button type="button" data-edge-back aria-label="Back" class="material-symbols-outlined cursor-pointer p-1.5 rounded-full hover:bg-theme-surface-variant">arrow_back</button>' : '<span class="w-2"></span>')
                .'<span class="font-semibold text-lg text-theme-on-surface"'.$navFont.'>'.static::e($title).'</span>'
                .'<span class="flex-1"></span>'.$actionsHtml
                .'</div>'
                .$search
                .'</header>';
        }

        $tabBar = '';
        if ($tabs !== [] && empty($p['hide_tab_bar'])) {
            // Active tab: an explicit `active` prop (set server-side by
            // TabBar::highlight()) wins. Otherwise longest-prefix match of
            // each tab's url against current_uri — the same rule
            // highlight() uses, so pushed detail screens keep their owning
            // tab lit.
            $hasExplicit = false;
            foreach ($tabs as $tab) {
                if (! empty($tab['props']['active'])) {
                    $hasExplicit = true;
                    break;
                }
            }

            if (! $hasExplicit) {
                $current = '/'.ltrim((string) ($p['current_uri'] ?? ''), '/');
                $bestIdx = -1;
                $bestLen = -1;
                foreach ($tabs as $i => $tab) {
                    $url = (string) ($tab['props']['url'] ?? '');
                    if ($url === '') {
                        continue;
                    }
                    $url = '/'.ltrim($url, '/');
                    if (($url === $current || str_starts_with($current, $url.'/')) && strlen($url) > $bestLen) {
                        $bestIdx = $i;
                        $bestLen = strlen($url);
                    }
                }
                if ($bestIdx >= 0) {
                    $tabs[$bestIdx]['props']['active'] = true;
                }
            }

            // navItem() emits data-edge-navigate from each item's url, so
            // every tab is a real web navigation target.
            $items = '';
            foreach ($tabs as $tab) {
                $items .= static::navItem($tab, $tab['props'] ?? [], bottom: true);
            }

            $tabBar = '<nav data-edge-tab-bar class="flex flex-row justify-around items-stretch w-full border-t border-theme-outline-variant bg-theme-surface shrink-0">'.$items.'</nav>';
        }

        return '<div'.static::idAttr($node).' class="flex flex-col h-full w-full overflow-hidden">'
            .$header
            .'<div class="flex flex-col flex-1 min-h-0 overflow-y-auto">'.$contentHtml.'</div>'
            .$tabBar
            .'</div>';
    }

    protected static function topBar(array $node, array $p, array $ctx): string
    {
        $back = ! empty($p['show_navigation_icon']);

        return '<header'.static::idAttr($node)
            .' class="flex flex-row items-center gap-2 px-2 py-2.5 bg-theme-surface border-b border-theme-outline-variant shrink-0 sticky top-0 z-10 w-full '.static::webClass($node).'">'
            .($back ? '<button type="button" data-edge-back class="material-symbols-outlined cursor-pointer p-1.5 rounded-full hover:bg-theme-surface-variant">arrow_back</button>' : '')
            .(isset($p['title']) && $p['title'] !== '' ? '<span class="font-semibold text-lg text-theme-on-surface">'.static::e($p['title']).'</span>' : '')
            .(isset($p['subtitle']) && $p['subtitle'] !== '' ? '<span class="text-sm text-theme-on-surface-variant">'.static::e($p['subtitle']).'</span>' : '')
            .'<span class="flex-1"></span>'
            .static::children($node, $ctx)
            .'</header>';
    }

    protected static function topBarAction(array $node, array $p): string
    {
        $inner = ! empty($p['icon'])
            ? '<span class="material-symbols-outlined">'.static::e(static::materialName($p['icon'])).'</span>'
            : static::e($p['label'] ?? '');

        $nav = isset($p['url']) && $p['url'] !== ''
            ? ' data-edge-navigate="'.static::e($p['url']).'"'
            : '';

        return '<button type="button"'.static::idAttr($node).static::pressAttrs($node, true).$nav
            .' class="cursor-pointer p-1.5 rounded-full hover:bg-theme-surface-variant text-theme-on-surface '
            .(! empty($p['destructive']) ? 'text-theme-destructive ' : '').static::webClass($node).'">'
            .$inner.'</button>';
    }

    protected static function navItem(array $node, array $p, bool $bottom): string
    {
        $active = ! empty($p['active']);

        $icon = ! empty($p['icon'])
            ? '<span class="material-symbols-outlined">'.static::e(static::materialName($p['icon'])).'</span>'
            : '';

        $badge = isset($p['badge']) && $p['badge'] !== '' && $p['badge'] !== null
            ? '<span class="absolute -top-1 -right-2 min-w-4 h-4 px-1 rounded-full bg-theme-destructive text-theme-on-destructive text-[10px] inline-flex items-center justify-center">'.static::e((string) $p['badge']).'</span>'
            : '';

        $nav = isset($p['url']) && $p['url'] !== '' ? ' data-edge-navigate="'.static::e($p['url']).'"' : '';

        $cls = $bottom
            ? 'flex flex-col items-center gap-0.5 px-3 py-2 text-xs cursor-pointer flex-1 '
            : 'flex flex-row items-center gap-3 px-4 py-2.5 text-sm cursor-pointer w-full text-left rounded-lg ';
        $cls .= $active ? 'text-theme-primary font-medium' : 'text-theme-on-surface-variant';

        return '<button type="button"'.static::idAttr($node).static::pressAttrs($node, true).$nav
            .' class="'.$cls.' '.static::webClass($node).'">'
            .'<span class="relative">'.$icon.$badge.'</span>'
            .(isset($p['label']) && $p['label'] !== '' ? '<span>'.static::e($p['label']).'</span>' : '')
            .'</button>';
    }

    protected static function sideNavGroup(array $node, array $p, array $ctx): string
    {
        return '<div'.static::idAttr($node).' class="flex flex-col gap-1 px-2 py-2 '.static::webClass($node).'">'
            .(isset($p['heading']) && $p['heading'] !== '' ? '<div class="px-2 pt-2 pb-1 text-xs font-medium uppercase tracking-wide text-theme-on-surface-variant">'.static::e($p['heading']).'</div>' : '')
            .static::children($node, $ctx).'</div>';
    }

    protected static function sideNavHeader(array $node, array $p): string
    {
        return '<div'.static::idAttr($node).' class="flex flex-col gap-1 px-4 py-4 border-b border-theme-outline-variant '.static::webClass($node).'">'
            .(isset($p['title']) ? '<span class="font-semibold text-theme-on-surface">'.static::e($p['title']).'</span>' : '')
            .(isset($p['subtitle']) ? '<span class="text-sm text-theme-on-surface-variant">'.static::e($p['subtitle']).'</span>' : '')
            .'</div>';
    }

    protected static function webview(array $node, array $p): string
    {
        if (isset($p['html']) && $p['html'] !== '') {
            return '<iframe'.static::idAttr($node).' srcdoc="'.static::e($p['html']).'" class="w-full flex-1 min-h-40 border-0 '.static::webClass($node).'"></iframe>';
        }

        return '<iframe'.static::idAttr($node).' src="'.static::e($p['src'] ?? 'about:blank').'" class="w-full flex-1 min-h-40 border-0 '.static::webClass($node).'"></iframe>';
    }

    // ── Attribute helpers ───────────────────────────

    protected static function idAttr(array $node): string
    {
        return ' data-edge-id="'.((int) ($node['id'] ?? 0)).'"'.static::ariaAttr($node);
    }

    /**
     * Shared ARIA passthrough. `a11y_label` (iOS accessibilityLabel /
     * Android contentDescription — see Concerns\HasA11y) becomes
     * aria-label on every element; idAttr() rides on all emitters so no
     * per-renderer wiring is needed.
     */
    protected static function ariaAttr(array $node): string
    {
        $label = (string) ($node['props']['a11y_label'] ?? '');

        return $label !== '' ? ' aria-label="'.static::e($label).'"' : '';
    }

    /**
     * Press bindings. $isNativeButton controls whether cursor styling is
     * already implied by the element type.
     */
    protected static function pressAttrs(array $node, bool $isNativeButton): string
    {
        $attrs = '';
        $p = $node['props'] ?? [];

        // Press callbacks arrive two ways on the wire: most elements carry
        // a node-level `on_press` field, but some plugin elements (notably
        // mobile-ui `button`) ride it in the PROPS dict instead — the same
        // channel as on_change — and native renderers read it from there.
        $pressId = $node['on_press'] ?? $p['on_press'] ?? null;

        if ($pressId !== null) {
            $attrs .= ' data-edge-press="'.((int) $pressId).'"';
            if (! $isNativeButton) {
                $attrs .= ' role="button" tabindex="0"';
            }
        }
        if (isset($node['on_long_press'])) {
            $attrs .= ' data-edge-long-press="'.((int) $node['on_long_press']).'"';
        }
        if (isset($p['on_double_tap'])) {
            $attrs .= ' data-edge-press="'.((int) $p['on_double_tap']).'"'; // POC: double-tap fires on click
        }

        return $attrs;
    }

    /** Combined class attribute: type base + author's raw Tailwind classes. */
    protected static function cls(array $node, string $base): string
    {
        $web = static::webClass($node);
        $press = isset($node['on_press']) ? 'cursor-pointer ' : '';

        return trim($press.$base.' '.$web);
    }

    /** The author's raw class string, with native bracket units mapped to px. */
    protected static function webClass(array $node): string
    {
        $raw = $node['props']['web_class'] ?? '';

        if ($raw === '') {
            return '';
        }

        // `web:` class variant — the Tailwind sibling of ios:/android:.
        // Native's parser drops it as an unknown platform; here it applies
        // (or drops entirely in the mobile dev preview, mirroring device).
        $raw = static::$mobilePreview
            ? preg_replace('/(^|\s)web:\S+/', '$1', $raw)
            : preg_replace('/(^|\s)web:/', '$1', $raw);

        return preg_replace_callback(
            '/([a-z][a-z0-9-]*)-\[(-?\d+(?:\.\d+)?)\]/',
            function ($m) {
                return in_array($m[1], static::UNITLESS, true)
                    ? $m[0]
                    : "{$m[1]}-[{$m[2]}px]";
            },
            $raw,
        );
    }

    /** ARGB (#AARRGGBB) wire colors → CSS #RRGGBBAA. */
    protected static function cssColor(string $hex): string
    {
        if (strlen($hex) === 9 && str_starts_with($hex, '#')) {
            return '#'.substr($hex, 3).substr($hex, 1, 2);
        }

        return $hex;
    }

    /** Common SF Symbol → Material Symbols translations. */
    protected const SF_TO_MATERIAL = [
        'chevron.down' => 'keyboard_arrow_down',
        'chevron.up' => 'keyboard_arrow_up',
        'chevron.left' => 'chevron_left',
        'chevron.right' => 'chevron_right',
        'chevron.backward' => 'chevron_left',
        'chevron.forward' => 'chevron_right',
        'arrow.left' => 'arrow_back',
        'arrow.right' => 'arrow_forward',
        'xmark' => 'close',
        'checkmark' => 'check',
        'plus' => 'add',
        'minus' => 'remove',
        'magnifyingglass' => 'search',
        'gear' => 'settings',
        'gearshape' => 'settings',
        'trash' => 'delete',
        'pencil' => 'edit',
        'square.and.pencil' => 'edit_square',
        'square.and.arrow.up' => 'share',
        'heart' => 'favorite',
        'heart.fill' => 'favorite',
        'star' => 'star',
        'star.fill' => 'star',
        'person' => 'person',
        'person.fill' => 'person',
        'person.circle' => 'account_circle',
        'house' => 'home',
        'house.fill' => 'home',
        'bell' => 'notifications',
        'bell.fill' => 'notifications',
        'camera' => 'photo_camera',
        'camera.fill' => 'photo_camera',
        'photo' => 'image',
        'paperplane' => 'send',
        'paperplane.fill' => 'send',
        'ellipsis' => 'more_horiz',
        'ellipsis.vertical' => 'more_vert',
        'line.3.horizontal' => 'menu',
        'bag' => 'shopping_bag',
        'cart' => 'shopping_cart',
        'envelope' => 'mail',
        'lock' => 'lock',
        'calendar' => 'calendar_month',
        'clock' => 'schedule',
        'info.circle' => 'info',
        'questionmark.circle' => 'help',
        'exclamationmark.triangle' => 'warning',
        'play' => 'play_arrow',
        'play.fill' => 'play_arrow',
        'pause' => 'pause',
        'bookmark' => 'bookmark',
        'doc' => 'description',
        'folder' => 'folder',
        'link' => 'link',
        'map' => 'map',
        'mappin' => 'location_on',
        'music.note' => 'music_note',
        'speaker.wave.2' => 'volume_up',
        'mic' => 'mic',
        'phone' => 'call',
        'video' => 'videocam',
        'globe' => 'public',
        'flame' => 'local_fire_department',
        'bolt' => 'bolt',
        'eye' => 'visibility',
        'eye.slash' => 'visibility_off',
        'hand.thumbsup' => 'thumb_up',
        'arrow.clockwise' => 'refresh',
        'arrow.trianglehead.2.clockwise' => 'sync',
        'square.grid.2x2' => 'grid_view',
        'list.bullet' => 'list',
        'slider.horizontal.3' => 'tune',
        'paintpalette' => 'palette',
        'textformat' => 'text_format',
    ];

    /**
     * Icon name → Material Symbols ligature. Android enum values are
     * already snake_case Material names and pass through. SF Symbols
     * (dotted names) get a best-effort translation; unknowns fall back
     * to a neutral glyph instead of the ligature degrading into giant
     * raw text.
     */
    protected static function materialName(string $name): string
    {
        $name = strtolower($name);

        if (isset(static::SF_TO_MATERIAL[$name])) {
            return static::SF_TO_MATERIAL[$name];
        }

        if (str_contains($name, '.')) {
            // Unknown SF Symbol: try the base name (drop .fill/.circle etc.)
            $base = explode('.', $name)[0];

            return static::SF_TO_MATERIAL[$base] ?? 'circle';
        }

        return str_replace('-', '_', $name);
    }

    protected static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
