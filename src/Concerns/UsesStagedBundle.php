<?php

namespace Native\Mobile\Concerns;

trait UsesStagedBundle
{
    /**
     * The Laravel app that native:run both already staged for this build,
     * if any. Commands without the option always stage their own.
     */
    protected function stagedBundleOption(): ?string
    {
        if (! $this->hasOption('staged-bundle')) {
            return null;
        }

        return $this->option('staged-bundle') ?: null;
    }
}
