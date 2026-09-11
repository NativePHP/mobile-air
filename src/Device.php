<?php

namespace Native\Mobile;

class Device
{
    /**
     * Process-cached current thermal state. Seeded on first read via a bridge
     * probe (`Device.GetThermalState`) and kept fresh by the ThermalStateChanged
     * event — a listener registered in NativeServiceProvider calls
     * rememberThermalState() when the OS reports a new status. Native also
     * re-reads on foreground (Android onResume / iOS didBecomeActive) and
     * emits only if the value drifted while we were away — the same nudge
     * appearance gets from a configuration / colorScheme change.
     */
    private static ?ThermalState $thermalState = null;

    public function getId(): ?string
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Device.GetId', '{}');
            if ($result) {
                $decoded = json_decode($result, true);

                return $decoded['id'] ?? null;
            }
        }

        return null;
    }

    public function getInfo(): ?string
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Device.GetInfo', '{}');
            if ($result) {
                $decoded = json_decode($result, true);

                return $decoded['info'] ?? null;
            }
        }

        return null;
    }

    public function getBatteryInfo(): ?string
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Device.GetBatteryInfo', '{}');
            if ($result) {
                $decoded = json_decode($result, true);

                return $decoded['info'] ?? null;
            }
        }

        return null;
    }

    /**
     * Vibrate the device with a short haptic feedback.
     *
     * @return bool True if vibration was triggered, false otherwise
     */
    public function vibrate(): bool
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Device.Vibrate', '{}');
            if ($result) {
                $decoded = json_decode($result, true);

                return isset($decoded['success']) && $decoded['success'] === true;
            }
        }

        return false;
    }

    /**
     * Toggle the device flashlight on/off.
     *
     * @return array Array with 'success' (bool) and 'state' (bool, on=true, off=false)
     */
    public function flashlight(): array
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Device.ToggleFlashlight', '{}');
            if ($result) {
                $decoded = json_decode($result, true);

                return [
                    'success' => $decoded['success'] ?? false,
                    'state' => $decoded['state'] ?? false,
                ];
            }
        }

        return [
            'success' => false,
            'state' => false,
        ];
    }

    /**
     * Current device thermal state. Off the device (tests, web preview) the
     * bridge is absent and this returns ThermalState::Normal. Android 8–9
     * has no thermal API and also reports Normal.
     */
    public function thermalState(): ThermalState
    {
        if (self::$thermalState !== null) {
            return self::$thermalState;
        }

        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Device.GetThermalState', '{}');
            $state = ThermalState::tryFrom(json_decode($result ?: '{}', true)['state'] ?? '');
            if ($state !== null) {
                return self::$thermalState = $state;
            }
        }

        return ThermalState::Normal;
    }

    /**
     * Update the process-cached thermal state. Called by the ThermalStateChanged
     * listener so thermalState() stays fresh without re-probing the bridge.
     */
    public static function rememberThermalState(ThermalState $state): void
    {
        self::$thermalState = $state;
    }

    /**
     * Drop the process-cached thermal state so the next read probes the bridge.
     */
    public static function forgetThermalState(): void
    {
        self::$thermalState = null;
    }
}
