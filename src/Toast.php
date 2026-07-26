<?php

namespace Native\Mobile;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;

class Toast
{
    /**
     * Show a plain text toast using the platform's default styling.
     */
    public function message(string $message): PendingToast
    {
        return (new PendingToast)->message($message);
    }

    /**
     * Show a toast rendered from one of your own views, so you control
     * exactly how it looks.
     *
     * @param  View|Htmlable|string  $view  A view name, view instance or renderable
     * @param  array<string, mixed>  $data
     */
    public function view(View|Htmlable|string $view, array $data = []): PendingToast
    {
        return (new PendingToast)->view($view, $data);
    }

    /**
     * Show a toast rendered from a raw HTML fragment or document.
     */
    public function html(string $html): PendingToast
    {
        return (new PendingToast)->html($html);
    }

    /**
     * Dismiss a specific toast by its ID, whether it's at the front of the
     * stack or still waiting behind other toasts.
     */
    public function dismiss(string $id): void
    {
        $this->call('Toast.Dismiss', ['id' => $id]);
    }

    /**
     * Dismiss every toast currently in the stack.
     */
    public function dismissAll(): void
    {
        $this->call('Toast.DismissAll', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function call(string $method, array $payload): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        if (function_exists('nativephp_can') && ! nativephp_can($method)) {
            return;
        }

        nativephp_call($method, json_encode($payload));
    }
}
