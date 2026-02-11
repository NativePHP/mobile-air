<?php

namespace Native\Mobile\Traits;

trait DisplaysMarketingBanners
{
    /**
     * Display the main NativePHP Pro banner with logo.
     * Best used after major success moments (install, build complete).
     */
    protected function showProBanner(): void
    {
        if ($this->option('quiet')) {
            return;
        }

        $this->newLine();
        $this->line('<fg=#FF00FF>  ███╗   ██╗ █████╗ ████████╗██╗██╗   ██╗███████╗██████╗ ██╗  ██╗██████╗</>');
        $this->line('<fg=#DD33FF>  ████╗  ██║██╔══██╗╚══██╔══╝██║██║   ██║██╔════╝██╔══██╗██║  ██║██╔══██╗</>');
        $this->line('<fg=#AA55FF>  ██╔██╗ ██║███████║   ██║   ██║██║   ██║█████╗  ██████╔╝███████║██████╔╝</>');
        $this->line('<fg=#7788FF>  ██║╚██╗██║██╔══██║   ██║   ██║╚██╗ ██╔╝██╔══╝  ██╔═══╝ ██╔══██║██╔═══╝</>');
        $this->line('<fg=#44BBFF>  ██║ ╚████║██║  ██║   ██║   ██║ ╚████╔╝ ███████╗██║     ██║  ██║██║</>');
        $this->line('<fg=#00FFFF>  ╚═╝  ╚═══╝╚═╝  ╚═╝   ╚═╝   ╚═╝  ╚═══╝  ╚══════╝╚═╝     ╚═╝  ╚═╝╚═╝</>');
        $this->newLine();
        $this->line('  <fg=white;options=bold>From</> <fg=green>laravel new</> <fg=white;options=bold>to App Store...</>');
        $this->newLine();

        $this->line('  <fg=yellow;options=bold>⚡ Bifrost</> <fg=gray>—</> <fg=white>Ship to stores</> <fg=cyan>→</> <fg=cyan;options=underscore>bifrost.nativephp.com</>');
        $this->line('  <fg=magenta;options=bold>🔌 Plugins</> <fg=gray>—</> <fg=white>Native features</> <fg=magenta>→</> <fg=magenta;options=underscore>nativephp.com/plugins</>');
        $this->line('  <fg=white;options=bold>📚 Docs</>    <fg=gray>—</> <fg=white>Get started</> <fg=gray>→</> <fg=gray;options=underscore>nativephp.com/docs/mobile</>');
        $this->newLine();
    }

    /**
     * Display Bifrost-focused banner.
     * Best used after successful builds when deployment is the next step.
     */
    protected function showBifrostBanner(): void
    {
        if ($this->option('quiet')) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=yellow;options=bold>⚡ BIFROST</>');
        $this->newLine();
        $this->line('  <fg=white;options=bold>Ship to App Store & Play Store in one click</>');
        $this->newLine();
        $this->line('  <fg=green>✓</> <fg=gray>Certificates & provisioning profiles — handled</>');
        $this->line('  <fg=green>✓</> <fg=gray>Code signing for iOS & Android — automated</>');
        $this->line('  <fg=green>✓</> <fg=gray>Direct uploads to both stores — one command</>');
        $this->newLine();
        $this->line('  <fg=cyan>→</> <fg=cyan;options=underscore>bifrost.nativephp.com</>');
        $this->newLine();
    }

    /**
     * Display Marketplace banner.
     * Best used when no plugins installed or after plugin creation.
     */
    protected function showMarketplaceBanner(): void
    {
        if ($this->option('quiet')) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=magenta;options=bold>🔌 PLUGIN MARKETPLACE</>');
        $this->newLine();
        $this->line('  <fg=white;options=bold>Add native features to your app in minutes</>');
        $this->newLine();
        $this->line('  <fg=cyan>Camera</> · <fg=cyan>Biometrics</> · <fg=cyan>Maps</> · <fg=cyan>Push</> · <fg=gray>and more...</>');
        $this->newLine();
        $this->line('  <fg=magenta>→</> <fg=magenta;options=underscore>nativephp.com/plugins</>');
        $this->newLine();
    }

    /**
     * Display plugin publish banner.
     * Best used after creating a new plugin.
     */
    protected function showPublishPluginBanner(): void
    {
        if ($this->option('quiet')) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=green;options=bold>💰 PUBLISH YOUR PLUGIN</>');
        $this->newLine();
        $this->line('  <fg=white>Share your plugin with thousands of NativePHP</>');
        $this->line('  <fg=white>developers and earn from the Marketplace.</>');
        $this->newLine();
        $this->line('  <fg=yellow>★</> <fg=gray>Free to list</>');
        $this->line('  <fg=yellow>★</> <fg=gray>Set your own price (or make it free)</>');
        $this->line('  <fg=yellow>★</> <fg=gray>80% revenue share</>');
        $this->newLine();
        $this->line('  <fg=green>→</> <fg=green;options=underscore>nativephp.com/plugins/publish</>');
        $this->newLine();
    }

    /**
     * Display a compact inline banner with border.
     * Best used when space is limited or for subtle promotion.
     */
    protected function showCompactBanner(string $type = 'bifrost'): void
    {
        if ($this->option('quiet')) {
            return;
        }

        $this->newLine();

        match ($type) {
            'bifrost' => $this->showCompactBifrost(),
            'marketplace' => $this->showCompactMarketplace(),
            default => $this->showCompactBifrost(),
        };

        $this->newLine();
    }

    private function showCompactBifrost(): void
    {
        $this->line('  <fg=yellow;options=bold>⚡ Bifrost</> <fg=gray>— Ship to App Store & Play Store</>');
        $this->line('  <fg=cyan>→</> <fg=cyan;options=underscore>bifrost.nativephp.com</>');
    }

    private function showCompactMarketplace(): void
    {
        $this->line('  <fg=magenta;options=bold>🔌 Plugins</> <fg=gray>— Native device features for your app</>');
        $this->line('  <fg=magenta>→</> <fg=magenta;options=underscore>nativephp.com/plugins</>');
    }
}
