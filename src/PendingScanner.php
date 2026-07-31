<?php

namespace Native\Mobile;

use Illuminate\Support\Str;
use Native\Mobile\Concerns\HandlesNativeCallbacks;
use Native\Mobile\Events\Scanner\CodeScanned;
use Native\Mobile\Events\Scanner\ScannerCancelled;

/**
 * @method $this codeScanned(\Closure|array|string $callback)
 * @method $this scannerCancelled(\Closure|array|string $callback)
 */
class PendingScanner
{
    use HandlesNativeCallbacks;

    protected ?string $eventClass = CodeScanned::class;

    protected ?string $prompt = null;

    protected bool $continuous = false;

    protected array $formats = ['qr'];

    protected ?string $id = null;

    protected bool $started = false;

    public function __construct() {}

    /**
     * Set the prompt text shown on the scanner screen.
     */
    public function prompt(string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    /**
     * Enable continuous scanning (scan multiple codes without closing).
     * Default: false (scan once and close)
     */
    public function continuous(bool $continuous = true): self
    {
        $this->continuous = $continuous;

        return $this;
    }

    /**
     * Set which barcode formats to scan.
     * Options: 'qr', 'ean13', 'ean8', 'code128', 'code39', 'upca', 'upce', 'all'
     * Default: ['qr']
     */
    public function formats(array $formats): self
    {
        $this->formats = $formats;

        return $this;
    }

    /**
     * Set a unique identifier for this scan session.
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the scan session ID (generates one if not set).
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = (string) Str::uuid();
        }

        return $this->id;
    }

    /**
     * Failure events for a scan: cancellation / permission denial.
     *
     * @return array<int, class-string>
     */
    protected function failureEvents(): array
    {
        return [ScannerCancelled::class];
    }

    /**
     * Start the QR code scanner.
     */
    public function scan(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        if (function_exists('nativephp_call')) {
            nativephp_call(
                'Scanner.Scan',
                json_encode([
                    'prompt' => $this->prompt ?? 'Scan QR Code',
                    'continuous' => $this->continuous,
                    'formats' => $this->formats,
                    'id' => $this->getId(),
                    'event' => $this->eventClass,
                ])
            );
        }
    }

    /**
     * Automatically start the scanner if scan() wasn't explicitly called.
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->scan();
        }
    }
}
