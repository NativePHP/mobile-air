<?php

namespace Native\Mobile\Edge\Asgard\Contracts;

/**
 * A component that names its own Asgard address and state instead of
 * having them derived from its class and public props.
 *
 * Asgard's ServerScreen is one: a single class shows every server screen,
 * so its address is the screen it was opened for, and its state is the
 * route's parameters and query (docs/ASGARD-PROTOCOL.md §12, "Screens").
 */
interface AddressedComponent
{
    /** The dot-notation address requests for this component go to. */
    public function asgardAddress(): string;

    /**
     * The state requests for this component carry: scalars, null and
     * arrays of those.
     *
     * @return array<string, mixed>
     */
    public function asgardState(): array;
}
