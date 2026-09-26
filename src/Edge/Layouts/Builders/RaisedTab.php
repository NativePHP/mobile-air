<?php

namespace Native\Mobile\Edge\Layouts\Builders;

use BackedEnum;
use InvalidArgumentException;
use Native\Mobile\Concerns\HasPlatformIcon;
use Native\Mobile\Edge\TailwindParser;

/**
 * Style for a raised tab: a tab of the native tab bar drawn as a disc that
 * rises out of the bar (the Instagram / TikTok "create" button pattern).
 *
 * Passed to `Tab::raised()`; a bare `->raised()` uses these defaults.
 *
 *   TabBar::make()
 *       ->add(Tab::link('Home', '/home', icon: 'home'))
 *       ->add(Tab::link('Create', '/create', icon: 'add')->raised(
 *           RaisedTab::make()->gradient('#3FBFA0', '#17977F')->ring('white', 4)
 *       ))
 *       ->add(Tab::link('Inbox', '/inbox', icon: 'inbox'));
 *
 * It stays a real tab: the bar keeps its label, selection state, badge and
 * tap handling, and a tap on the disc (including the part raised above the
 * bar) does exactly what a tap on the tab does. The disc shows the tab's own
 * icon unless `icon()` sets a different one; the tab's slot in the bar is
 * left blank under it.
 *
 * Sizes are dp on Android and points on iOS, range-checked when set.
 * Colours take the element colour grammar ({@see TailwindParser::resolveColorOrThemeToken()}):
 * `#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA`, palette names such as `teal-500`,
 * `white`, `black`, `transparent`, each with an optional `/N` opacity, and
 * `theme-<token>` (its light value, as in a gradient stop). An 8-digit hex
 * is CSS order, `#RRGGBBAA` — unlike the raw strings `TabBar`'s own colours
 * pass through, which the natives read as `#AARRGGBB`. An unsupported
 * colour throws.
 */
class RaisedTab
{
    use HasPlatformIcon;

    /** The `raised-*` attributes `<native:bottom-nav-item>` accepts. */
    private const ATTRIBUTES = [
        'size', 'lift', 'color', 'gradient-from', 'gradient-to', 'gradient-angle',
        'icon', 'ios-icon', 'android-icon', 'icon-color', 'icon-size',
        'ring-color', 'ring-width', 'halo-color', 'halo-width',
        'shadow', 'shadow-color', 'shadow-elevation', 'press-scale', 'dock-with-keyboard',
    ];

    private int $size = 52;

    private int $lift = 20;

    private ?string $color = null;

    private ?string $gradientFrom = null;

    private ?string $gradientTo = null;

    private int $gradientAngle = 135;

    private string $iconColor = '#FFFFFF';

    private int $iconSize = 26;

    private ?string $ringColor = null;

    private int $ringWidth = 0;

    private ?string $haloColor = null;

    private int $haloWidth = 0;

    private bool $shadow = true;

    private ?string $shadowColor = null;

    private int $shadowElevation = 8;

    private float $pressScale = 0.94;

    private bool $dockWithKeyboard = true;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Build a style from `<native:bottom-nav-item>` attributes: every
     * `raised-*` attribute (kebab or snake case) maps onto the fluent
     * method of the same name, so the Blade path gets the same validation
     * as the builder.
     *
     *   <native:bottom-nav-item id="create" label="Create" url="/create" icon="add"
     *       raised raised-color="teal-500" raised-ring-color="white" raised-ring-width="4" />
     *
     * @param  array<string, mixed>  $attrs
     */
    public static function fromAttributes(array $attrs): self
    {
        $raised = new self;
        $value = fn (string $name): mixed => $attrs['raised-'.$name] ?? $attrs['raised_'.str_replace('-', '_', $name)] ?? null;

        // A typo (`raised-colour`) would otherwise raise the tab with a
        // silently ignored style.
        foreach (array_keys($attrs) as $key) {
            if (! is_string($key) || ! preg_match('/^raised[-_](.+)$/', $key, $m)) {
                continue;
            }
            if (! in_array(str_replace('_', '-', $m[1]), self::ATTRIBUTES, true)) {
                throw new InvalidArgumentException("Unknown raised tab attribute [{$key}]. Known: raised-".implode(', raised-', self::ATTRIBUTES).'.');
            }
        }
        foreach (['gradient-angle' => 'gradient-from', 'ring-width' => 'ring-color', 'halo-width' => 'halo-color'] as $dependent => $needs) {
            if ($value($dependent) !== null && $value($needs) === null) {
                throw new InvalidArgumentException("raised-{$dependent} needs raised-{$needs}.");
            }
        }

        if (($size = $value('size')) !== null) {
            $raised->size(self::integer($size, 'raised-size'));
        }
        if (($lift = $value('lift')) !== null) {
            $raised->lift(self::integer($lift, 'raised-lift'));
        }
        if (($color = $value('color')) !== null) {
            $raised->color((string) $color);
        }
        if (($from = $value('gradient-from')) !== null || $value('gradient-to') !== null) {
            $to = $value('gradient-to');
            if ($from === null || $to === null) {
                throw new InvalidArgumentException('A raised gradient needs both raised-gradient-from and raised-gradient-to.');
            }
            $angle = $value('gradient-angle');
            $raised->gradient((string) $from, (string) $to, $angle === null ? 135 : self::integer($angle, 'raised-gradient-angle'));
        }

        $icon = $value('icon');
        $iosIcon = $value('ios-icon');
        $androidIcon = $value('android-icon');
        if ($icon !== null || $iosIcon !== null || $androidIcon !== null) {
            // A bound enum in the shared slot is tolerated, as on the item itself.
            if ($icon instanceof BackedEnum) {
                $icon = (string) $icon->value;
            }
            $raised->icon(is_string($icon) ? $icon : null, $iosIcon, $androidIcon);
        }
        if (($iconColor = $value('icon-color')) !== null) {
            $raised->iconColor((string) $iconColor);
        }
        if (($iconSize = $value('icon-size')) !== null) {
            $raised->iconSize(self::integer($iconSize, 'raised-icon-size'));
        }

        if (($ringColor = $value('ring-color')) !== null) {
            $ringWidth = $value('ring-width');
            $raised->ring((string) $ringColor, $ringWidth === null ? 3 : self::integer($ringWidth, 'raised-ring-width'));
        }
        if (($haloColor = $value('halo-color')) !== null) {
            $haloWidth = $value('halo-width');
            $raised->activeHalo((string) $haloColor, $haloWidth === null ? 3 : self::integer($haloWidth, 'raised-halo-width'));
        }

        if (($shadow = $value('shadow')) !== null && ! self::boolean($shadow)) {
            $raised->withoutShadow();
        } elseif (($shadowColor = $value('shadow-color')) !== null || $value('shadow-elevation') !== null) {
            $elevation = $value('shadow-elevation');
            $raised->shadow(
                $shadowColor === null ? null : (string) $shadowColor,
                $elevation === null ? 8 : self::integer($elevation, 'raised-shadow-elevation'),
            );
        }

        if (($pressScale = $value('press-scale')) !== null) {
            if (! is_numeric($pressScale)) {
                throw new InvalidArgumentException("raised-press-scale must be a number, got [{$pressScale}].");
            }
            $raised->pressScale((float) $pressScale);
        }
        if (($dock = $value('dock-with-keyboard')) !== null) {
            $raised->dockWithKeyboard(self::boolean($dock));
        }

        return $raised;
    }

    /**
     * Whether any attribute in the bag styles a raised tab, which on its
     * own makes the item raised (`raised-color="…"` without a bare
     * `raised`).
     *
     * @param  array<string, mixed>  $attrs
     */
    public static function hasStyleAttributes(array $attrs): bool
    {
        foreach (array_keys($attrs) as $key) {
            if (is_string($key) && (str_starts_with($key, 'raised-') || str_starts_with($key, 'raised_'))) {
                return true;
            }
        }

        return false;
    }

    /** Diameter of the disc's fill (24–120). The ring and halo sit outside it. */
    public function size(int $points): self
    {
        $this->size = self::between($points, 24, 120, 'size');

        return $this;
    }

    /** How far the top of the fill rises above the bar's top edge (0–80). */
    public function lift(int $points): self
    {
        $this->lift = self::between($points, 0, 80, 'lift');

        return $this;
    }

    /**
     * Solid fill. Without a colour or gradient the disc uses the tab bar's
     * `activeColor()`, then the platform accent colour. `color()` and
     * `gradient()` replace each other; the last call wins.
     */
    public function color(string $color): self
    {
        $this->color = self::normalizeColor($color, 'color');
        $this->gradientFrom = null;
        $this->gradientTo = null;

        return $this;
    }

    /**
     * Linear gradient fill. The angle follows CSS `linear-gradient()`:
     * 0 points up, 90 right, 180 down.
     */
    public function gradient(string $from, string $to, int $angle = 135): self
    {
        $this->gradientFrom = self::normalizeColor($from, 'gradient');
        $this->gradientTo = self::normalizeColor($to, 'gradient');
        $this->gradientAngle = (($angle % 360) + 360) % 360;
        $this->color = null;

        return $this;
    }

    /** Colour of the icon inside the disc. Defaults to white. */
    public function iconColor(string $color): self
    {
        $this->iconColor = self::normalizeColor($color, 'iconColor');

        return $this;
    }

    /** Size of the icon inside the disc (12–64). */
    public function iconSize(int $points): self
    {
        $this->iconSize = self::between($points, 12, 64, 'iconSize');

        return $this;
    }

    /** A ring around the fill, drawn outside it like a CSS `box-shadow` spread. Width 0–16. */
    public function ring(string $color = '#FFFFFF', int $width = 3): self
    {
        $this->ringColor = self::normalizeColor($color, 'ring');
        $this->ringWidth = self::between($width, 0, 16, 'ring width');

        return $this;
    }

    /**
     * An extra ring outside the ring, shown only while the raised tab is
     * the selected tab. Its space is always reserved, so the disc never
     * shifts when it appears. Width 0–16.
     */
    public function activeHalo(string $color, int $width = 3): self
    {
        $this->haloColor = self::normalizeColor($color, 'activeHalo');
        $this->haloWidth = self::between($width, 0, 16, 'halo width');

        return $this;
    }

    /** Drop shadow, on by default. The colour defaults to the fill colour (a gradient's end colour). Elevation 0–32. */
    public function shadow(?string $color = null, int $elevation = 8): self
    {
        $this->shadow = true;
        $this->shadowColor = $color === null ? null : self::normalizeColor($color, 'shadow');
        $this->shadowElevation = self::between($elevation, 0, 32, 'shadow elevation');

        return $this;
    }

    public function withoutShadow(): self
    {
        $this->shadow = false;

        return $this;
    }

    /** Scale the disc animates to while pressed (0.5–1.0); 1.0 turns the effect off. */
    public function pressScale(float $scale): self
    {
        if ($scale < 0.5 || $scale > 1.0) {
            throw new InvalidArgumentException("RaisedTab pressScale must be between 0.5 and 1.0, got {$scale}.");
        }

        $this->pressScale = $scale;

        return $this;
    }

    /**
     * While the keyboard is open the disc steps out of the way instead of
     * sitting over the field being typed into: on Android, where the tab
     * bar rides above the keyboard, it settles into the bar; on iOS, where
     * the keyboard covers the bar, it fades out. Pass false to keep it
     * raised.
     */
    public function dockWithKeyboard(bool $dock = true): self
    {
        $this->dockWithKeyboard = $dock;

        return $this;
    }

    /**
     * The wire props merged onto the tab's `bottom_nav_item`. The disc's
     * icon is only sent when `icon()` set one; otherwise the renderers use
     * the tab's own icon.
     *
     * @return array<string, string|int|float|bool>
     */
    public function toProps(): array
    {
        $props = [
            'raised' => true,
            'raised_size' => $this->size,
            'raised_lift' => $this->lift,
            'raised_icon_color' => $this->iconColor,
            'raised_icon_size' => $this->iconSize,
            'raised_shadow' => $this->shadow,
            'raised_shadow_elevation' => $this->shadowElevation,
            'raised_press_scale' => $this->pressScale,
            'raised_dock_with_keyboard' => $this->dockWithKeyboard,
        ];

        if (($icon = $this->resolvedIcon()) !== null) {
            $props['raised_icon'] = $icon;
        }
        if ($this->color !== null) {
            $props['raised_color'] = $this->color;
        }
        if ($this->gradientFrom !== null && $this->gradientTo !== null) {
            $props['raised_gradient_from'] = $this->gradientFrom;
            $props['raised_gradient_to'] = $this->gradientTo;
            $props['raised_gradient_angle'] = $this->gradientAngle;
        }
        if ($this->ringColor !== null && $this->ringWidth > 0) {
            $props['raised_ring_color'] = $this->ringColor;
            $props['raised_ring_width'] = $this->ringWidth;
        }
        if ($this->haloColor !== null && $this->haloWidth > 0) {
            $props['raised_halo_color'] = $this->haloColor;
            $props['raised_halo_width'] = $this->haloWidth;
        }
        if ($this->shadowColor !== null) {
            $props['raised_shadow_color'] = $this->shadowColor;
        }

        return $props;
    }

    /** Normalise to the `#RRGGBB` / `#AARRGGBB` wire format both native colour parsers read. */
    private static function normalizeColor(string $value, string $method): string
    {
        return TailwindParser::resolveColorOrThemeToken(trim($value)) ?? throw new InvalidArgumentException(
            "RaisedTab {$method}() got an unsupported colour [{$value}]. Use hex (#RGB, #RGBA, #RRGGBB, #RRGGBBAA — CSS order), a palette name such as teal-500 (optionally with /opacity), or theme-<token>."
        );
    }

    private static function between(int $value, int $min, int $max, string $name): int
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("RaisedTab {$name} must be between {$min} and {$max}, got {$value}.");
        }

        return $value;
    }

    private static function integer(mixed $value, string $attribute): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        throw new InvalidArgumentException("{$attribute} must be a whole number, got [".(is_scalar($value) ? $value : get_debug_type($value)).'].');
    }

    private static function boolean(mixed $value): bool
    {
        return is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
