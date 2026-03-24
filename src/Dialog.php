<?php

namespace Native\Mobile;

use Native\Mobile\Facades\Share;

class Dialog
{
    /**
     * Share content via the native share sheet.
     *
     * @deprecated Use \Native\Mobile\Facades\Share::url() instead
     */
    #[\Deprecated(message: 'Use \Native\Mobile\Facades\Share::url() instead', since: '2.0.0')]
    public function share(string $title, string $text, string $url): void
    {
        // Delegate to Share::url() which uses the god method
        Share::url($title, $text, $url);
    }

    public function alert(string $title, string $message, array $buttons = []): PendingAlert
    {
        return new PendingAlert($title, $message, $buttons);
    }

    public function toast(string $message, string $duration = 'long'): void
    {
        if (function_exists('nativephp_call')) {
            $payload = json_encode([
                'message' => $message,
                'duration' => $duration,
            ]);

            nativephp_call('Dialog.Toast', $payload);
        }
    }
}
