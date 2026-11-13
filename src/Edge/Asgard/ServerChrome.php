<?php

namespace Native\Mobile\Edge\Asgard;

/**
 * The client's check of a server screen's chrome (docs/ASGARD-PROTOCOL.md
 * §12, "Chrome").
 *
 * The server checks chrome before it sends it. The client checks it again
 * here, because it came off the wire: every key is known, every value has
 * its type, every button slot passes FragmentCallbacks::parseSlot(), a tab
 * URL is an app path, and the content slots pass the same checks as a
 * region's fragment. One bad part refuses the whole chrome, which leaves
 * the screen on the chrome it had.
 */
final class ServerChrome
{
    private const MAX_STRING = 1024;

    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,100}$/D';

    private const NAV_BAR_STRINGS = ['title', 'subtitle', 'background_color', 'text_color', 'font'];

    private const TAB_BAR_STRINGS = ['active_color', 'background_color', 'text_color', 'font'];

    /**
     * The checked chrome, with slots in canonical form and content as node
     * lists, or null with `$reason`.
     */
    public static function parse(mixed $chrome, ?string &$reason = null): ?array
    {
        $reason = null;

        try {
            return self::chrome($chrome);
        } catch (\UnexpectedValueException $e) {
            $reason = $e->getMessage();

            return null;
        }
    }

    private static function chrome(mixed $chrome): array
    {
        $chrome = self::object($chrome, 'chrome');
        $clean = [];

        foreach ($chrome as $key => $value) {
            $path = "chrome.{$key}";

            $clean[$key] = match ($key) {
                'native' => self::bool($value, $path),
                'font' => self::string($value, $path),
                'nav_bar' => self::navBar($value, $path),
                'tab_bar' => self::tabBar($value, $path),
                'bottom_bar', 'tab_accessory' => self::content($value, $path),
                default => self::fail("{$path} is not a chrome field"),
            };
        }

        return $clean;
    }

    private static function navBar(mixed $bar, string $path): array
    {
        $clean = [];

        foreach (self::object($bar, $path) as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match (true) {
                in_array($key, ['hidden', 'back'], true) => self::bool($value, $at),
                in_array($key, self::NAV_BAR_STRINGS, true) => self::string($value, $at),
                $key === 'elevation' => self::int($value, $at, 0, 64),
                $key === 'display_mode' => self::oneOf($value, $at, ['large', 'inline', 'automatic']),
                $key === 'scroll_behavior' => self::oneOf($value, $at, ['collapse', 'pinned', 'enterAlways']),
                $key === 'logo' => self::logo($value, $at),
                $key === 'title_view' => self::content($value, $at),
                $key === 'search' => self::search($value, $at, true),
                $key === 'actions' => self::list($value, $at, 16, fn ($action, $p) => self::action($action, $p, true)),
                default => self::fail("{$at} is not a nav bar field"),
            };
        }

        return $clean;
    }

    private static function action(mixed $action, string $path, bool $topLevel): array
    {
        $action = self::object($action, $path);
        self::id($action['id'] ?? null, "{$path}.id");

        if (($action['divider'] ?? false) === true) {
            return array_keys($action) === ['id', 'divider'] || array_keys($action) === ['divider', 'id']
                ? ['id' => $action['id'], 'divider' => true]
                : self::fail("{$path} is a divider, which has only an id");
        }

        $clean = [];
        foreach ($action as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match (true) {
                $key === 'id' => $value,
                in_array($key, ['label', 'a11y_label', 'icon', 'ios_icon', 'android_icon'], true) => self::string($value, $at),
                $key === 'image' => self::media($value, $at),
                in_array($key, ['destructive', 'disabled'], true) => self::bool($value, $at),
                $key === 'on_press' => self::slot($value, $at, 'on_press'),
                $key === 'items' && $topLevel => self::list($value, $at, 32, fn ($item, $p) => self::action($item, $p, false)),
                default => self::fail("{$at} is not an action field"),
            };
        }

        return $clean;
    }

    private static function tabBar(mixed $bar, string $path): array
    {
        $clean = [];

        foreach (self::object($bar, $path) as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match (true) {
                in_array($key, ['hidden', 'dark', 'minimize_on_scroll'], true) => self::bool($value, $at),
                $key === 'highlight' => self::id($value, $at),
                in_array($key, self::TAB_BAR_STRINGS, true) => self::string($value, $at),
                $key === 'label_visibility' => self::oneOf($value, $at, ['labeled', 'selected', 'unlabeled']),
                $key === 'presentation' => self::presentation($value, $at),
                $key === 'tabs' => self::list($value, $at, 10, fn ($tab, $p) => self::tab($tab, $p)),
                default => self::fail("{$at} is not a tab bar field"),
            };
        }

        return $clean;
    }

    private static function tab(mixed $tab, string $path): array
    {
        $tab = self::object($tab, $path);
        self::id($tab['id'] ?? null, "{$path}.id");
        self::string($tab['label'] ?? null, "{$path}.label");

        $clean = [];
        foreach ($tab as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match (true) {
                in_array($key, ['id', 'label'], true) => $value,
                in_array($key, ['icon', 'ios_icon', 'android_icon', 'badge', 'badge_color'], true) => self::string($value, $at),
                $key === 'url' => FragmentCallbacks::isAppPath($value) ? $value : self::fail("{$at} must be an app path"),
                in_array($key, ['news', 'active'], true) => self::bool($value, $at),
                $key === 'search' => self::search($value, $at, false),
                $key === 'on_press' => self::slot($value, $at, 'on_press'),
                default => self::fail("{$at} is not a tab field"),
            };
        }

        return $clean;
    }

    private static function logo(mixed $logo, string $path): array
    {
        $logo = self::object($logo, $path);

        if (array_diff(array_keys($logo), ['src', 'height']) !== []) {
            self::fail("{$path} is {\"src\", \"height\"?}");
        }

        $height = $logo['height'] ?? 28;

        if (! is_int($height) && ! is_float($height) || $height <= 0 || $height > 200) {
            self::fail("{$path}.height must be a number up to 200");
        }

        return ['src' => self::media($logo['src'] ?? null, "{$path}.src"), 'height' => (float) $height];
    }

    /** A URL the device loads: absolute https (or http under app.debug), never an app asset path. */
    private static function media(mixed $value, string $path): string
    {
        $value = self::string($value, $path);

        if ($value === '' || ($problem = MediaUrl::problem($value)) !== null) {
            self::fail("{$path} ".($problem ?? 'must not be empty'));
        }

        return $value;
    }

    private static function search(mixed $search, string $path, bool $query): array
    {
        $clean = [];

        foreach (self::object($search, $path) as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match (true) {
                $key === 'placeholder' => self::string($value, $at),
                $key === 'debounce_ms' => self::int($value, $at, 0, 5000),
                // The query is appended to args, as for on_change, so it can never navigate.
                $key === 'on_query' && $query => self::slot($value, $at, 'on_change'),
                default => self::fail("{$at} is not a search field"),
            };
        }

        return $clean;
    }

    private static function presentation(mixed $presentation, string $path): array
    {
        $presentation = self::object($presentation, $path);
        $id = $presentation['id'] ?? null;

        if (array_diff(array_keys($presentation), ['id', 'configuration']) !== [] || ! is_string($id) || strlen($id) > 128
            || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[a-z0-9]+(?:[._-][a-z0-9]+)*)?\z/', $id) !== 1) {
            self::fail("{$path} is {\"id\", \"configuration\"?} with a lowercase id");
        }

        $configuration = $presentation['configuration'] ?? [];

        if (! is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
            self::fail("{$path}.configuration must be an object");
        }

        return ['id' => $id, 'configuration' => $configuration];
    }

    private static function slot(mixed $slot, string $path, string $name): array
    {
        return FragmentCallbacks::parseSlot($slot, $reason, $name) ?? self::fail("{$path}: {$reason}");
    }

    private static function content(mixed $content, string $path): array
    {
        return ServerRegion::checkContent($content, $reason) ?? self::fail("{$path}: {$reason}");
    }

    private static function object(mixed $value, string $path): array
    {
        return is_array($value) && ($value === [] || ! array_is_list($value)) ? $value : self::fail("{$path} must be an object");
    }

    private static function list(mixed $value, string $path, int $max, \Closure $each): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            self::fail("{$path} must be a list of at most {$max}");
        }

        return array_map(fn ($item, $i) => $each($item, "{$path}[{$i}]"), $value, array_keys($value));
    }

    private static function bool(mixed $value, string $path): bool
    {
        return is_bool($value) ? $value : self::fail("{$path} must be a boolean");
    }

    private static function string(mixed $value, string $path): string
    {
        return is_string($value) && strlen($value) <= self::MAX_STRING ? $value : self::fail("{$path} must be a short string");
    }

    private static function id(mixed $value, string $path): string
    {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1 ? $value : self::fail("{$path} must be an id");
    }

    private static function int(mixed $value, string $path, int $min, int $max): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : self::fail("{$path} must be an integer from {$min} to {$max}");
    }

    private static function oneOf(mixed $value, string $path, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : self::fail("{$path} must be one of ".implode(', ', $allowed));
    }

    private static function fail(string $reason): never
    {
        throw new \UnexpectedValueException($reason);
    }
}
