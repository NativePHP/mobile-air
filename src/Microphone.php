<?php

namespace Native\Mobile;

class Microphone
{
    /**
     * Start microphone recording
     */
    public function record(): PendingMicrophone
    {
        return new PendingMicrophone;
    }

    /**
     * Stop microphone recording
     *
     * The recorded audio path will be dispatched via the MicrophoneRecorded event to Livewire.
     * Event is dispatched directly from native code via JavaScript injection.
     */
    public function stop(): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Microphone.Stop', json_encode([]));
        }
    }

    /**
     * Pause the current microphone recording
     */
    public function pause(): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Microphone.Pause', json_encode([]));
        }
    }

    /**
     * Resume a paused microphone recording
     */
    public function resume(): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Microphone.Resume', json_encode([]));
        }
    }

    /**
     * Get the current recording status
     *
     * @return string Status: "idle", "recording", or "paused"
     */
    public function getStatus(): string
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Microphone.GetStatus', json_encode([]));

            if ($result) {
                $decoded = json_decode($result, true);

                return $decoded['status'] ?? 'idle';
            }
        }

        return 'idle';
    }

    /**
     * How loud the microphone has been since this was last called, from 0 (silence, or nothing
     * recording) to 1 (the loudest a sample can be), for drawing a level meter while recording.
     * Needs a microphone plugin that reports levels; older ones read as 0.
     */
    public function level(): float
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Microphone.GetLevel', json_encode([]));

            if ($result) {
                $decoded = json_decode($result, true);

                return is_numeric($decoded['level'] ?? null) ? max(0.0, min(1.0, (float) $decoded['level'])) : 0.0;
            }
        }

        return 0.0;
    }

    /**
     * Get the path to the last recorded audio file
     *
     * @return string|null Path to the last recording, or null if none exists
     */
    public function getRecording(): ?string
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Microphone.GetRecording', json_encode([]));

            if ($result) {
                $decoded = json_decode($result, true);
                $path = $decoded['path'] ?? '';

                return $path !== '' ? $path : null;
            }
        }

        return null;
    }
}
