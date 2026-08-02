<?php

namespace Native\Mobile\Edge\Web\Bridge;

/**
 * Marker driver for the WebDriverRegistry: the bridge method has no
 * server-side implementation on the web target — instead the WebBridge
 * queues an effect `{method, params}` that rides out on the response
 * (`effects: [...]`) for the client runtime (edge-web.js) to perform in
 * the browser (show a dialog, vibrate, open a URL, ask for geolocation…).
 *
 * The optional mapper rewrites the decoded call params into the params
 * the client effect should carry:
 *
 *     WebDriverRegistry::register('Dialog.Toast', ClientEffect::make(
 *         fn (array $p) => ['message' => $p['message'] ?? '']
 *     ));
 */
class ClientEffect
{
    final protected function __construct(protected ?\Closure $map = null) {}

    public static function make(?\Closure $map = null): static
    {
        return new static($map);
    }

    /** The params to queue on the effect, after any mapping. */
    public function params(array $params): array
    {
        return $this->map === null ? $params : (array) ($this->map)($params);
    }
}
