<?php

namespace Native\Mobile;

use Native\Mobile\Facades\Share;
use Native\Mobile\Facades\Toast;

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

    /**
     * Show a native alert dialog.
     *
     * Each button is either a plain string or an array with a style:
     * ['label' => 'Delete', 'style' => 'destructive']. Styles: default,
     * cancel, destructive.
     */
    public function alert(string $title, string $message, array $buttons = []): PendingAlert
    {
        return new PendingAlert($title, $message, $buttons);
    }

    /**
     * Show a toast.
     *
     * @deprecated Use \Native\Mobile\Facades\Toast::message() instead
     */
    #[\Deprecated(message: 'Use \Native\Mobile\Facades\Toast::message() instead', since: '4.0.0')]
    public function toast(string $message, string $duration = 'long'): void
    {
        Toast::message($message)->duration($duration)->show();
    }
}
