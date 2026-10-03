<?php

namespace Native\Mobile\Icon;

/**
 * Marks a backed enum whose values name Windows icons, the way [IosSymbol]
 * and [AndroidSymbol] mark the SF Symbols and Material enums.
 *
 * A value is what the Windows shell draws from its icon font, Segoe Fluent
 * Icons: a code point written `U+E710`, or one of the glyph names WinUI's
 * `Symbol` enum knows (`Add`, `Accept`, `Globe`).
 */
interface WindowsSymbol {}
