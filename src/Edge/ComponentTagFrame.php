<?php

namespace Native\Mobile\Edge;

/**
 * Stack marker pushed by NativeElementCollector when an OPEN `<native:*>`
 * tag resolves to a registered child component instead of an element.
 *
 * The compiled template's matching close() call finds this frame on top of
 * the stack and mounts the child there; any element collected while the
 * frame is on top is slot content, which v1 rejects (see
 * ComponentSlotNotSupportedException).
 */
final class ComponentTagFrame
{
    public function __construct(
        public readonly string $tag,
        public readonly array $attrs,
    ) {}
}
