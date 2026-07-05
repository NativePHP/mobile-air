<?php

namespace Native\Mobile\Icon;

/**
 * Marker for iOS (SF Symbol) enums.
 *
 * Lives in core so core builders (NavAction, Tab) can type-hint icon
 * args against it without depending on the native-ui plugin's concrete
 * `Ios` enum catalog.
 *
 * Implementing enums must be string-backed; the icon resolver reads
 * `->value` to get the canonical SF Symbol name.
 */
interface IosSymbol {}
