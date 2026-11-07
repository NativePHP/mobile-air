<?php

namespace Native\Mobile\Edge\Asgard;

/**
 * One ask for one region's fragment, handed to the bound FragmentSource.
 *
 * `component` is the dot-notation address of the component that rendered
 * the region (ComponentAddress, protocol §4), which is a child component
 * when the `@server` block sits in a child's view. `state` is that
 * component's public props, kept to what JSON can carry: scalars, null,
 * and arrays of those. A prop holding anything else (an object, an enum,
 * a non-finite float) is left out whole rather than half-sent.
 *
 * `id` is client-local. It identifies this request so a late answer to a
 * superseded one can be told apart and dropped, and it is not part of
 * the wire body. Nor is `componentClass`, which is here for local use
 * only: the server MUST NOT see a class name (§4).
 */
final class RegionRequest
{
    public function __construct(
        public readonly string $id,
        public readonly string $component,
        public readonly string $region,
        public readonly array $state,
        public readonly string $platform,
        public readonly ?string $componentClass = null,
    ) {}

    /** The body a transport signs, encrypts and sends. */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'region' => $this->region,
            'state' => $this->state,
            'platform' => $this->platform,
        ];
    }
}
