<?php

namespace Native\Mobile\Icon;

/**
 * Marker for SF Symbol enums.
 *
 * Lives in core so core builders (NavAction, Tab) can type-hint icon
 * args against it without depending on the native-ui plugin's concrete
 * `SF` enum catalog.
 *
 * Implementing enums must be string-backed; the icon resolver reads
 * `->value` to get the canonical SF name.
 */
interface SFSymbol
{
}
