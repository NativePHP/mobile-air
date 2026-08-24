<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Enums\AlignItems;
use Native\Mobile\Edge\Enums\AlignSelf;
use Native\Mobile\Edge\Enums\JustifyContent;

abstract class Element
{
    use Concerns\HasA11y;

    protected string $type;

    protected ?int $nodeId = null;

    /**
     * Caller-supplied stable identity for this node, set via `->key($k)`.
     *
     * When non-null, `toArray()` derives `nodeId` deterministically as
     * `fnv1a32(parentKeyPath . '/' . $key)`, so the same logical node
     * keeps the same id across renders even when its position shifts
     * (insert/reorder/remove of siblings). Children of a keyed node
     * inherit the parent's key-path; unkeyed children fall back to
     * positional index *within their keyed parent* so they stay stable
     * as long as the parent is keyed and order doesn't change.
     *
     * Without a key, ids fall back to the legacy positional
     * `$nextId++` — fine for static screens but breaks identity when
     * lists reorder or insert at the head. (See REFACTOR-native-ui-
     * performance.md Phase 1 — "index-as-key is a trap": prefer a
     * stable domain id (e.g. `$todo->id`), not the loop index.)
     */
    protected ?string $key = null;

    /**
     * The element's name, set via `->ref('save-btn')` or a Blade
     * `ref="save-btn"` attribute. Emitted on the wire node so the testing
     * harness can locate elements without depending on visible text or method
     * names (the `data-testid` equivalent).
     *
     * It is ALSO the shared-element identity: two elements on different
     * screens sharing a ref morph into each other during a `view_transition`
     * navigation (see NodeHeroModifier on iOS). A ref that exists purely as a
     * test handle and must never travel can opt out with `morph="none"`.
     */
    protected ?string $elementRef = null;

    protected array $layout = [];

    protected array $style = [];

    protected ?string $pressMethod = null;

    protected ?string $longPressMethod = null;

    protected ?string $doubleTapMethod = null;

    protected ?string $pressDownMethod = null;

    protected ?string $pressUpMethod = null;

    protected ?string $swipeDeleteMethod = null;

    protected ?array $navigateConfig = null;

    protected array $children = [];

    protected array $darkProps = [];

    /**
     * Generic key-value props applied to every element (set by Tailwind
     * parser dispatch / `setProp(...)`). Carried through to the
     * serialized node's props map alongside any subclass-specific
     * resolveProps() output.
     */
    protected array $extraProps = [];

    /**
     * Inline-chrome placement marker — set by the boolean `custom`
     * attribute on `<native:top-bar>` / `<native:bottom-nav>` /
     * `<native:side-nav>`. A custom chrome element stays in the content
     * tree and renders as an ordinary drawn element (the Column-style
     * custom chrome), instead of being hoisted onto the native chrome
     * root. It still suppresses the layout's bar for that slot. See
     * NativeComponent::wrapWithChrome().
     */
    protected bool $customChrome = false;

    /**
     * The CallbackRegistry this element's callbacks belong to, when it was
     * collected inside a CHILD component's render scope — set by
     * NativeElementCollector so `toArray()` registers @tap / native:model
     * handlers into the child's registry (dispatch then resolves the id
     * back to the child instance). Null (the default) means "use whatever
     * registry the caller passes", i.e. the screen's.
     */
    protected ?CallbackRegistry $ownedCallbacks = null;

    public function markCustomChrome(bool $custom = true): static
    {
        $this->customChrome = $custom;

        return $this;
    }

    public function isCustomChrome(): bool
    {
        return $this->customChrome;
    }

    /**
     * Pin this element's callbacks to a specific registry (the owning
     * child component's). Overrides the registry toArray() receives.
     */
    public function ownCallbacks(CallbackRegistry $callbacks): static
    {
        $this->ownedCallbacks = $callbacks;

        return $this;
    }

    // ── Attribute hydration ──────────────────────────────

    /**
     * Apply Blade attributes to this element.
     *
     * Plugin elements override this to map Blade attrs to their props.
     * Built-in elements use the instanceof chain in NativeElementCollector instead.
     */
    public function applyAttributes(array $attrs): void
    {
        // No-op by default — built-in elements use applyElementProps()
    }

    // ── Tree building ─────────────────────────────────

    public function addChild(Element $child): static
    {
        $this->children[] = $child;

        return $this;
    }

    /**
     * Replace this element's child list wholesale. Used when hoisting a
     * sentinel out of the tree (e.g. an inline `<native:bottom-bar>` lifted
     * up to the native-chrome root) so the extracted node isn't rendered
     * twice.
     *
     * @param  Element[]  $children
     */
    public function setChildren(array $children): static
    {
        $this->children = array_values($children);

        return $this;
    }

    /**
     * Apply a string of Tailwind utility classes to this element using
     * the same parser the blade collector uses. Lets layout-side
     * Element composition (e.g. `NativeLayout::bottomBar()`) read like
     * blade markup:
     *
     *   Row::make()->class('px-3 gap-2 items-center glass rounded-full')
     *       ->addChild(...)
     *
     * Parses through `TailwindParser` and dispatches to the same
     * applyLayout / applyStyle / applyElementProps helpers
     * `NativeElementCollector` uses for blade-driven elements, so the
     * resolved keys map to the same node fields with no behavioural
     * drift between blade and programmatic construction.
     */
    public function class(string $classes): static
    {
        $attrs = TailwindParser::parse($classes);
        // Same order the collector uses for blade elements (applyAttributes
        // FIRST) so element-specific styling — Text font-size/weight/color,
        // Image fit/tint, etc. — resolves identically whether an element is
        // built from blade or programmatically. Without this, class strings
        // that only carry element-specific keys silently no-op on directly
        // built elements.
        $this->applyAttributes($attrs);
        NativeElementCollector::applyLayout($this, $attrs);
        NativeElementCollector::applyStyle($this, $attrs);
        NativeElementCollector::applyElementProps($this, $attrs);
        // Dark companions — `dark:` variants and theme-token dark hexes
        // parse into a `dark` sub-array that the collector splits into
        // `dark_*` props for blade elements. Without the same split here,
        // programmatically built elements silently dropped ALL dark-mode
        // styling (light hexes rendered in both modes).
        $this->mergeDarkProps(NativeElementCollector::buildDarkProps($attrs));

        return $this;
    }

    // ── Layout methods ───────────────────────────────

    public function width(float|string $value): static
    {
        $this->layout['width'] = $value;

        return $this;
    }

    public function height(float|string $value): static
    {
        $this->layout['height'] = $value;

        return $this;
    }

    public function fill(): static
    {
        $this->layout['width'] = 'fill';
        $this->layout['height'] = 'fill';

        return $this;
    }

    public function fillWidth(): static
    {
        $this->layout['width'] = 'fill';

        return $this;
    }

    public function fillHeight(): static
    {
        $this->layout['height'] = 'fill';

        return $this;
    }

    public function padding(float ...$values): static
    {
        $this->layout['padding'] = match (count($values)) {
            1 => $values[0],
            2 => [$values[0], $values[1], $values[0], $values[1]],
            3 => [$values[0], $values[1], $values[2], $values[1]],
            4 => $values,
            default => $this->layout['padding'] ?? 0,
        };

        return $this;
    }

    public function margin(float ...$values): static
    {
        $this->layout['margin'] = match (count($values)) {
            1 => $values[0],
            2 => [$values[0], $values[1], $values[0], $values[1]],
            3 => [$values[0], $values[1], $values[2], $values[1]],
            4 => $values,
            default => $this->layout['margin'] ?? 0,
        };

        return $this;
    }

    public function gap(float $value): static
    {
        $this->layout['gap'] = $value;

        return $this;
    }

    public function flexGrow(float $value): static
    {
        $this->layout['flex_grow'] = $value;

        return $this;
    }

    public function flexShrink(float $value): static
    {
        $this->layout['flex_shrink'] = $value;

        return $this;
    }

    public function alignSelf(int|string|AlignSelf $value): static
    {
        if (($resolved = AlignSelf::parse($value)) !== null) {
            $this->layout['align_self'] = $resolved;
        }

        return $this;
    }

    public function alignItems(int|string|AlignItems $value): static
    {
        if (($resolved = AlignItems::parse($value)) !== null) {
            $this->layout['align_items'] = $resolved;
        }

        return $this;
    }

    public function justifyContent(int|string|JustifyContent $value): static
    {
        if (($resolved = JustifyContent::parse($value)) !== null) {
            $this->layout['justify_content'] = $resolved;
        }

        return $this;
    }

    public function center(): static
    {
        $this->layout['align_items'] = 1;
        $this->layout['justify_content'] = 1;

        return $this;
    }

    /**
     * Apply both the top (status-bar / notch) and bottom (home-indicator)
     * safe-area insets to this view's content. Encoded as `safe_area = 1`
     * for backward compatibility with the original single-flag semantics.
     */
    public function safeArea(): static
    {
        $this->layout['safe_area'] = 1;

        return $this;
    }

    /**
     * Apply only the top safe-area inset (status bar / notch). Useful for
     * a layout's wrapper column when there's a TabBar at the bottom — the
     * TabBar handles its own bottom inset, so the wrapper should free that
     * edge so the bar's bg can reach the screen edge.
     */
    public function safeAreaTop(): static
    {
        $this->layout['safe_area'] = 2;

        return $this;
    }

    /**
     * Apply only the bottom safe-area inset (home indicator / nav bar).
     * Symmetric to `safeAreaTop()` — used when a NavBar handles its own
     * top inset.
     */
    public function safeAreaBottom(): static
    {
        $this->layout['safe_area'] = 3;

        return $this;
    }

    // ── Extended layout methods (flexbox) ──────────────

    public function minWidth(float $value): static
    {
        $this->layout['min_width'] = $value;

        return $this;
    }

    public function minHeight(float $value): static
    {
        $this->layout['min_height'] = $value;

        return $this;
    }

    public function maxWidth(float $value): static
    {
        $this->layout['max_width'] = $value;

        return $this;
    }

    public function maxHeight(float $value): static
    {
        $this->layout['max_height'] = $value;

        return $this;
    }

    public function flexBasis(float|string $value): static
    {
        $this->layout['flex_basis'] = $value;

        return $this;
    }

    public function flexWrap(int $value = 1): static
    {
        $this->layout['flex_wrap'] = $value;

        return $this;
    }

    public function flexDirection(int $value): static
    {
        $this->layout['flex_direction'] = $value;

        return $this;
    }

    public function positionType(int $value): static
    {
        $this->layout['position_type'] = $value;

        return $this;
    }

    public function absolute(): static
    {
        $this->layout['position_type'] = 1;

        return $this;
    }

    public function insets(float ...$values): static
    {
        $this->layout['position'] = match (count($values)) {
            1 => [$values[0], $values[0], $values[0], $values[0]],
            2 => [$values[0], $values[1], $values[0], $values[1]],
            4 => $values,
            default => $this->layout['position'] ?? [0, 0, 0, 0],
        };

        return $this;
    }

    public function display(int $value): static
    {
        $this->layout['display'] = $value;

        return $this;
    }

    public function hidden(): static
    {
        $this->layout['display'] = 1;

        return $this;
    }

    public function overflow(int $value): static
    {
        $this->layout['overflow'] = $value;

        return $this;
    }

    public function alignContent(int $value): static
    {
        $this->layout['align_content'] = $value;

        return $this;
    }

    public function direction(int $value): static
    {
        $this->layout['direction'] = $value;

        return $this;
    }

    public function aspectRatio(float $value): static
    {
        $this->layout['aspect_ratio'] = $value;

        return $this;
    }

    public function rowGap(float $value): static
    {
        $this->layout['row_gap'] = $value;

        return $this;
    }

    // ── Style methods ────────────────────────────────

    public function bg(string $color): static
    {
        $this->style['bg_color'] = $color;

        return $this;
    }

    public function borderRadius(float $value): static
    {
        $this->style['border_radius'] = $value;

        return $this;
    }

    public function border(float $width, string $color): static
    {
        $this->style['border_width'] = $width;
        $this->style['border_color'] = $color;

        return $this;
    }

    public function opacity(float $value): static
    {
        $this->style['opacity'] = $value;

        return $this;
    }

    public function elevation(float $value): static
    {
        $this->style['elevation'] = $value;

        return $this;
    }

    // ── Node-level events ────────────────────────────

    public function onPress(string $method): static
    {
        $this->pressMethod = $method;

        return $this;
    }

    /** Alias of onPress() — the programmatic twin of the `@tap` directive. */
    public function onTap(string $method): static
    {
        return $this->onPress($method);
    }

    public function onSwipeDelete(string $method): static
    {
        $this->swipeDeleteMethod = $method;

        return $this;
    }

    public function onLongPress(string $method): static
    {
        $this->longPressMethod = $method;

        return $this;
    }

    /**
     * Double-tap handler. Carried in the props dict as `on_double_tap`
     * (see getResolvedProps) rather than as a dedicated node field, so it
     * needs no binary wire-format change.
     */
    public function onDoubleTap(string $method): static
    {
        $this->doubleTapMethod = $method;

        return $this;
    }

    /**
     * Touch-down handler — fires the moment the finger makes contact,
     * unlike `onPress` which is a completed tap (fires on release). Pair
     * with `onPressUp` for held-button semantics (gamepad d-pads, push-to-
     * talk). Both travel in the props dict (`on_press_down` /
     * `on_press_up`) and reuse the PRESS wire event — the callback id
     * alone routes to the handler — so no binary wire-format change.
     */
    public function onPressDown(string $method): static
    {
        $this->pressDownMethod = $method;

        return $this;
    }

    /**
     * Touch-up handler — fires on release OR gesture cancellation (system
     * gesture steals the touch, view disappears). Renderers guarantee an
     * up after every down so a held button is never left stuck.
     */
    public function onPressUp(string $method): static
    {
        $this->pressUpMethod = $method;

        return $this;
    }

    public function setNavigateConfig(array $config): static
    {
        $this->navigateConfig = $config;

        return $this;
    }

    public function mergeDarkProps(array $props): static
    {
        $this->darkProps = array_merge($this->darkProps, $props);

        return $this;
    }

    /**
     * Set a generic prop that flows through to the serialized node's
     * props map. Used for Tailwind-mapped properties that aren't part
     * of the layout / style schemas (e.g. `glass`).
     */
    public function setProp(string $key, mixed $value): static
    {
        $this->extraProps[$key] = $value;

        return $this;
    }

    /**
     * Opt this node's subtree into native text selection (long-press → Copy
     * menu). Programmatic mirror of the `select-text` / `select-none` Tailwind
     * classes; container-scoped and inherited on both platforms. Pass `false`
     * to carve a subtree back out of a selectable ancestor.
     */
    public function selectable(bool $on = true): static
    {
        $this->extraProps['selectable'] = $on ? 1 : 0;

        return $this;
    }

    // ── Defaults (override in subclasses) ──────────────

    protected function defaults(): array
    {
        return [];
    }

    protected function layoutDefaults(): array
    {
        return [];
    }

    protected function styleDefaults(): array
    {
        return [];
    }

    // ── Streaming getters ────────────────────────────

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return Element[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function getLayout(): array
    {
        return array_merge($this->layoutDefaults(), $this->layout);
    }

    public function getStyle(): array
    {
        return array_merge($this->styleDefaults(), $this->style);
    }

    public function getPressCallbackId(CallbackRegistry $registry): int
    {
        if ($this->navigateConfig !== null) {
            $navKey = $registry->registerNavigation($this->navigateConfig);

            return $registry->register("__navigate('{$navKey}')");
        }

        if ($this->pressMethod !== null) {
            return $registry->register($this->pressMethod);
        }

        return 0;
    }

    public function getLongPressCallbackId(CallbackRegistry $registry): int
    {
        if ($this->longPressMethod !== null) {
            return $registry->register($this->longPressMethod);
        }

        return 0;
    }

    public function getResolvedProps(CallbackRegistry $registry): array
    {
        $props = array_merge($this->defaults(), $this->resolveProps($registry));

        // Generic extras (Tailwind-dispatched glass / etc.) merged after
        // resolveProps so subclass props (`current_uri`, `nav_*`, …) take
        // precedence on key collision while still letting any element
        // carry through extras set via `setProp` / `class('glass')`.
        if (! empty($this->extraProps)) {
            $props = array_merge($this->extraProps, $props);
        }

        if ($this->swipeDeleteMethod !== null) {
            $props['on_swipe_delete'] = $registry->register($this->swipeDeleteMethod);
        }

        if ($this->doubleTapMethod !== null) {
            $props['on_double_tap'] = $registry->register($this->doubleTapMethod);
        }

        if ($this->pressDownMethod !== null) {
            $props['on_press_down'] = $registry->register($this->pressDownMethod);
        }

        if ($this->pressUpMethod !== null) {
            $props['on_press_up'] = $registry->register($this->pressUpMethod);
        }

        if (! empty($this->darkProps)) {
            $props = array_merge($props, $this->darkProps);
        }

        return $props;
    }

    // ── Identity ─────────────────────────────────────

    /**
     * Set a stable caller-supplied key for this node (Phase 1 — keyed
     * identity). Mirror of Livewire's `wire:key` / React's `key=`. Pass
     * a stable *domain* id (e.g. `$todo->id`), not the loop index — see
     * the docblock on `$key` and Phase 1 in the perf refactor doc.
     */
    /**
     * Name this node (see $elementRef).
     */
    public function ref(string $ref): static
    {
        $this->elementRef = $ref;

        // Also carried as a generic prop. The node-level `ref` field is NOT
        // serialized — no native node model parses it — so the prop bag is
        // what actually reaches the renderer, and the shared-element morph
        // reads it from there. Same value, two carriers, one authored name.
        $this->setProp('ref', $ref);

        return $this;
    }

    public function key(string|int $key): static
    {
        $this->key = (string) $key;

        return $this;
    }

    /**
     * FNV-1a 32-bit hash. Used to derive stable node ids from key-paths.
     * Inline implementation (no `hash()` / extension call per node) —
     * `toArray()` calls this per keyed node, which can be thousands.
     *
     * Exposed publicly so the Blade streaming path
     * (`NativeElementCollector`) can derive identical ids from a
     * `native:key=...` attribute — meaning the same key produces the
     * same node id whether the screen renders via Blade or via
     * programmatic Element::key().
     */
    public static function fnv1a32(string $s): int
    {
        $hash = 0x811C9DC5;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $hash ^= ord($s[$i]);
            // 64-bit PHP keeps the intermediate product as int; mask
            // back to 32-bit unsigned to match the C-side semantics.
            $hash = ($hash * 0x01000193) & 0xFFFFFFFF;
        }

        // Avoid id=0 — the C/native side treats 0 as "no callback set"
        // for callback ids and may also reserve it for sentinel meanings
        // in future flat-node fields. Cheap to nudge once than to track
        // a separate "is set" bit downstream.
        return $hash === 0 ? 1 : $hash;
    }

    /**
     * Resolve a key-path to a unique u32 id. On hash collision (rare;
     * 1 in ~2^32) perturb the path with a salt and rehash. Logs once
     * per collision so a real id storm doesn't go silent. Never returns
     * a duplicate within the current `$emittedIds` set — that would
     * break the native diff and Compose/SwiftUI `key()` semantics.
     */
    private static function deriveNodeIdFromKeyPath(string $keyPath, array &$emittedIds): int
    {
        $id = self::fnv1a32($keyPath);
        if (! isset($emittedIds[$id])) {
            $emittedIds[$id] = true;

            return $id;
        }

        // Collision — perturb deterministically. Loop bounded so a
        // pathological case doesn't hang; in practice we exit on the
        // first or second salt.
        for ($salt = 1; $salt < 64; $salt++) {
            $id = self::fnv1a32($keyPath."\x00".$salt);
            if (! isset($emittedIds[$id])) {
                error_log("Element::toArray — key collision at '{$keyPath}', resolved with salt={$salt}");
                $emittedIds[$id] = true;

                return $id;
            }
        }

        // Should never hit this with a real keyset. Fall back to a
        // monotonic stamp so the publish doesn't bail entirely; native
        // diff loses identity for this node but the rest of the frame
        // is preserved.
        $fallback = 0xC0000000 | (count($emittedIds) & 0x3FFFFFFF);
        error_log("Element::toArray — exhausted salts for '{$keyPath}', falling back to {$fallback}");
        $emittedIds[$fallback] = true;

        return $fallback;
    }

    // ── Legacy tree serialization ───────────────────

    public function toArray(
        CallbackRegistry $registry,
        int &$nextId = 1,
        string $parentKeyPath = '',
        int $indexInParent = 0,
        array &$emittedIds = [],
        array &$lastNodeHashes = []
    ): array {
        // An element collected inside a child component's render scope
        // carries that child's registry — its callbacks must register
        // there so dispatch resolves them back to the child instance.
        // Children still receive the caller's registry as the default;
        // each carries its own pin when it needs one.
        $registry = $this->ownedCallbacks ?? $registry;

        // Id precedence:
        //   1. Explicit `$this->nodeId` (caller set it directly — respect it).
        //   2. `$this->key` non-null → hash `parentKeyPath . '/' . key`.
        //   3. Inside a keyed subtree (parentKeyPath != '') → fall back to
        //      positional index *within the keyed parent* so unkeyed
        //      siblings stay stable as long as their parent is keyed.
        //   4. Otherwise → legacy `$nextId++` (pre-Phase-1 behavior).
        $myKeyPath = '';
        if ($this->nodeId !== null) {
            $id = $this->nodeId;
            $emittedIds[$id] = true;
            $myKeyPath = $parentKeyPath !== '' || $this->key !== null
                ? $parentKeyPath.'/'.($this->key ?? $indexInParent)
                : '';
        } elseif ($this->key !== null) {
            $myKeyPath = $parentKeyPath.'/'.$this->key;
            $id = self::deriveNodeIdFromKeyPath($myKeyPath, $emittedIds);
        } elseif ($parentKeyPath !== '') {
            $myKeyPath = $parentKeyPath.'/'.$indexInParent;
            $id = self::deriveNodeIdFromKeyPath($myKeyPath, $emittedIds);
        } else {
            $id = $nextId++;
            $emittedIds[$id] = true;
        }

        // Resolve fields once. We need them either to assemble the FULL
        // node or to feed them into the contentHash for the REUSE check.
        $layout = $this->getLayout();
        $style = $this->getStyle();
        $props = $this->getResolvedProps($registry);

        $onPress = null;
        $onLongPress = null;
        if ($this->pressMethod !== null) {
            $onPress = $registry->register($this->pressMethod);
        }
        if ($this->longPressMethod !== null) {
            $onLongPress = $registry->register($this->longPressMethod);
        }
        if ($this->navigateConfig !== null) {
            $navKey = $registry->registerNavigation($this->navigateConfig);
            $onPress = $registry->register("__navigate('{$navKey}')");
        }

        // Phase 2 — recurse children first so we can fold their content
        // hashes into ours (Merkle). Each child returns a `_hash` field
        // alongside its node data; we pluck it for our own hash compute.
        $childNodes = [];
        $childHashes = [];
        if (! empty($this->children)) {
            $childParentPath = $myKeyPath;
            $childIdx = 0;
            foreach ($this->children as $child) {
                $cn = $child->toArray(
                    $registry,
                    $nextId,
                    $childParentPath,
                    $childIdx++,
                    $emittedIds,
                    $lastNodeHashes
                );
                $childNodes[] = $cn;
                $childHashes[] = $cn['_hash'] ?? '';
            }
        }

        // Compute this node's contentHash from everything the renderer
        // reacts to. If a future per-field mutation test ever fails to
        // repaint, the missing field is here (§3 Phase 2 pitfalls:
        // "Hash the right things").
        $contentHash = hash('xxh3', serialize([
            $this->type,
            $layout,
            $style,
            $props,
            $onPress,
            $onLongPress,
            $this->elementRef,
            $childHashes,
        ]));

        // REUSE decision: if `$lastNodeHashes` carries a matching prior
        // hash for this id, emit a compact marker — no layout / style /
        // props / children. Native readers see NPHP_NODE_FLAG_REUSE and
        // splice the previous subtree by id. The caller (NativeComponent)
        // controls whether `$lastNodeHashes` is maintained across frames,
        // so this branch only fires when subtree-memo is opted in.
        $prior = $lastNodeHashes[$id] ?? null;
        if ($prior !== null && $prior === $contentHash) {
            return [
                'id' => $id,
                'type' => $this->type, // kept for native-side debug logging
                'flags' => 1,          // NPHP_NODE_FLAG_REUSE
                '_hash' => $contentHash,
            ];
        }

        // FULL emit — record this frame's hash so the next frame can
        // short-circuit if nothing changes.
        $lastNodeHashes[$id] = $contentHash;

        $node = [
            'id' => $id,
            'type' => $this->type,
            '_hash' => $contentHash,
        ];

        if (! empty($layout)) {
            $node['layout'] = $layout;
        }
        if (! empty($style)) {
            $node['style'] = $style;
        }
        if (! empty($props)) {
            $node['props'] = $props;
        }
        if ($onPress !== null) {
            $node['on_press'] = $onPress;
        }
        if ($onLongPress !== null) {
            $node['on_long_press'] = $onLongPress;
        }
        if ($this->elementRef !== null) {
            $node['ref'] = $this->elementRef;
        }
        if (! empty($childNodes)) {
            $node['children'] = $childNodes;
        }

        return $node;
    }

    // ── Resolution ───────────────────────────────────

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
