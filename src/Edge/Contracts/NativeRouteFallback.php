<?php

namespace Native\Mobile\Edge\Contracts;

/**
 * App-decided response for a native route reached WITHOUT a native
 * runtime — a shared app link opened in a plain desktop browser, a
 * crawler, a misconfigured deploy. Those requests can't be satisfied by
 * the runloop (there is no device attached to them), so left unbound
 * they behave exactly as before; bind an implementation to show
 * something useful instead — a landing page, an app-store redirect, a
 * QR handoff to the phone:
 *
 *     $this->app->singleton(NativeRouteFallback::class, StoreRedirect::class);
 */
interface NativeRouteFallback
{
    /**
     * Answer the current request for a native screen route.
     *
     * @return mixed a response or renderable
     */
    public function handle(string $componentClass);
}
