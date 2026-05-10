<?php

namespace Native\Mobile\Icon;

/**
 * Marker + variant interface for Material Icons enums.
 *
 * Lives in core so core builders can type-hint icon args without
 * depending on the native-ui plugin's concrete `Material` /
 * `MaterialOutlined` enum catalogs.
 *
 * Implementing enums must be string-backed (the resolver reads
 * `->value` to get the ligature name) AND return one of `'filled'` /
 * `'outlined'` from [variant] so the Compose `MaterialIcon` composable
 * picks the right font.
 */
interface MaterialSymbol
{
    /**
     * Returns `'filled'` or `'outlined'`. Drives the `material_variant`
     * wire prop emitted by [HasPlatformIcon::resolvedMaterialVariant].
     */
    public function variant(): string;
}
