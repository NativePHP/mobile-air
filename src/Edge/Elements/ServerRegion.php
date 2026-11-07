<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * The container a `@server('name') … @endserver` block opens — the
 * Asgard region primitive (docs/ASGARD-PROTOCOL.md §3).
 *
 * It holds exactly one of two child sets, never both: the placeholder
 * the app ships with, or the fragment a server returned for this
 * region. Which one is decided when the region opens — see
 * `Native\Mobile\Edge\Asgard\ServerRegion`.
 *
 * The node is always keyed with the region name, and that is load
 * bearing rather than cosmetic: `Element::toArray()` derives every
 * descendant's key path from its parent's, so keying the region is what
 * lands a fragment's nodes at `/<region>/<relative key>` instead of at
 * the root (§9.1). The streaming path gets the same prefix from the
 * collector's key-path stack.
 *
 * Layout-wise it is deliberately inert: no padding, no fill, no
 * direction. A region must not impose styling the placeholder or the
 * fragment did not ask for.
 *
 * ## On the wire it is a `column`
 *
 * Neither renderer knows `server_region`, and neither needs to. The node
 * leaves PHP as a `column` (see wireType()) with no props of its own,
 * which both platforms already paint as a plain vertical container
 * (ASGARD-PROTOCOL.md §12 q5). Only its key travels, because that is
 * what puts fragment nodes under `/<region>/…`. Anything the author
 * passed through `@server('x', ['class' => '…'])` still applies, the
 * same as on any other column.
 *
 * `server_region` stays the PHP identity: getType() reports it, the
 * element registry resolves it, and fragment validation refuses it.
 */
class ServerRegion extends Element
{
    protected string $type = 'server_region';

    protected array $props = [];

    public static function make(string $region = ''): static
    {
        $el = new static;

        if ($region !== '') {
            $el->region($region);
        }

        return $el;
    }

    /**
     * Name this region. Also sets the node key, because the name IS the
     * key-path segment every fragment node hangs off.
     */
    public function region(string $name): static
    {
        $this->props['region'] = $name;

        return $this->key($name);
    }

    public function regionName(): ?string
    {
        return $this->props['region'] ?? null;
    }

    public function applyAttributes(array $attrs): void
    {
        // The key half of region() is applied by the collector from the
        // `native-key` attr the directive passes alongside this one, so
        // both the Blade and the streaming path key the node identically.
        if (isset($attrs['region'])) {
            $this->props['region'] = (string) $attrs['region'];
        }
    }

    /** Paint as a plain `column`; see the class comment. */
    public function wireType(): string
    {
        return 'column';
    }

    /**
     * Nothing of the region's own goes to native. The name is already the
     * node's key, and a renderer has no use for it as a prop.
     */
    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
