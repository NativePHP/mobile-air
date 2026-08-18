<?php

namespace Native\Mobile\Concerns;

trait HasHotReloadPort
{
    /**
     * TCP port the in-app hot-reload server listens on.
     *
     * On a simulator this is bound on the host itself (the simulator shares
     * the host's localhost), and physical devices are tunnelled to the same
     * host port by iproxy. Either way the port is host-wide, so two apps that
     * want hot reload at the same time must be configured with different
     * ports.
     *
     * The value is baked into the app's Info.plist at build time, so changing
     * it requires a rebuild before the app will listen on the new port.
     *
     * iOS only — Android signals a reload by pushing a file into the app's
     * storage over adb, so it never binds a port.
     */
    protected function hotReloadPort(): int
    {
        // config() only falls back when the key is absent, so an app that has
        // published the key but left it empty would otherwise yield port 0.
        return (int) (config('nativephp.hot_reload.port') ?: 9999);
    }
}
