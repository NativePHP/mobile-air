<?php

namespace Native\Mobile\Edge\Asgard;

/**
 * A tap inside a region's fragment whose callback targets the server
 * (docs/ASGARD-PROTOCOL.md §10), handed to FragmentSource::action().
 *
 * It is a RegionRequest for the same region, carrying the state the
 * component had when the tap landed, plus the callback's `call` and
 * `args`. The server runs the action and answers with the region's new
 * fragment, which goes back through ServerRegion::deliver() like any
 * other answer. Sending one supersedes a region request still in
 * flight, so an older render cannot land on top of the action's.
 *
 * `call` and `args` are what the fragment's callback slot said, with
 * the control's value (a text field's text, a toggle's state) appended
 * to `args` for `on_change` and `on_submit`.
 */
class ActionRequest extends RegionRequest
{
    public function __construct(
        string $id,
        string $component,
        string $region,
        array $state,
        string $platform,
        public readonly string $call,
        public readonly array $args = [],
        ?string $componentClass = null,
    ) {
        parent::__construct($id, $component, $region, $state, $platform, $componentClass);
    }

    /** The render body, plus the action to run before rendering. */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'action' => [
                'call' => $this->call,
                'args' => $this->args,
            ],
        ];
    }
}
