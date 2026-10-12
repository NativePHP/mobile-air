<?php

namespace Native\Mobile\Support;

/**
 * A fresh identifier for each Laravel bundle the build packs.
 *
 * The device only re-extracts the bundle when its identity changes. The app
 * version and version code alone don't change between two local builds, so a
 * rebuild installed over the top kept running the previously extracted code
 * and compiled views. The build id is written into bundle_meta.json and the
 * app re-extracts once whenever it differs from the id it last extracted.
 */
class BundleBuildId
{
    public static function generate(): string
    {
        return date('YmdHis').'-'.bin2hex(random_bytes(4));
    }
}
