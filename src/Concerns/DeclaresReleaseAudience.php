<?php

namespace Native\Mobile\Concerns;

/**
 * A build made in any environment other than production is a testing build,
 * and both stores are told so at package time: Google Play through the
 * manifest's largest release audience, App Store Connect through TestFlight
 * internal testing. That declaration travels with the artifact, so a testing
 * build cannot later be promoted to a public release by hand.
 */
trait DeclaresReleaseAudience
{
    /**
     * The AndroidManifest meta-data Google Play reads to learn how far a
     * release may travel. Play expects the audience as a suffix of this name
     * (for example `.NONPRODUCTION`) and an empty `android:value`.
     *
     * @see https://developer.android.com/google/play/release-audience-restriction
     */
    protected const LARGEST_RELEASE_AUDIENCE_KEY = 'com.google.android.play.largest_release_audience';

    protected function buildsForProduction(): bool
    {
        return config('app.env') === 'production';
    }

    /**
     * The largest audience this build may be released to, or null when it is
     * a production build and no ceiling applies. NONPRODUCTION keeps every
     * testing track and internal app sharing open and blocks only the
     * production track, matching the Play upload guard.
     */
    protected function largestReleaseAudience(): ?string
    {
        return $this->buildsForProduction() ? null : 'NONPRODUCTION';
    }

    /**
     * Whether an App Store Connect upload from this build must be confined to
     * TestFlight's internal testers.
     */
    protected function restrictToInternalTestFlight(): bool
    {
        return ! $this->buildsForProduction();
    }
}
