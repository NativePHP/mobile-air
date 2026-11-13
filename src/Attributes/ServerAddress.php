<?php

namespace Native\Mobile\Attributes;

use Attribute;
use InvalidArgumentException;
use Native\Mobile\Edge\Asgard\ComponentAddress;

/**
 * Names the address a component's server half is found under
 * (docs/ASGARD-PROTOCOL.md §4).
 *
 *     #[ServerAddress('home')]
 *     class Home extends NativeComponent
 *
 * Without it the address is the class name in dot notation
 * (`app.native.screens.home`). With it, the server maps `home` to its
 * component and the client's class name is no longer part of the
 * protocol, so moving or renaming the class on the client changes
 * nothing on the server.
 *
 * The address is dot notation with lower-case snake_case segments, the
 * same shape the server's map accepts.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ServerAddress
{
    public function __construct(public string $address)
    {
        if (! preg_match(ComponentAddress::PATTERN, $address)) {
            throw new InvalidArgumentException("Asgard address [{$address}] must be dot notation with snake_case segments.");
        }
    }
}
