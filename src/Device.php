<?php

namespace Native\Mobile;

use Native\Mobile\Data\Localization;

class Device
{
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

    public function localization(): ?Localization
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Device.GetLocale', '{}');
            if ($result) {
                $decoded = json_decode($result, true);
                $info = json_decode($decoded['info'] ?? '{}', true);

                return new Localization(
                    locale: $info['locale'] ?? '',
                    languageCode: $info['languageCode'] ?? '',
                    regionCode: $info['regionCode'] ?? '',
                    timezone: $info['timezone'] ?? '',
                    currencyCode: $info['currencyCode'] ?? '',
                    preferredLanguage: $info['preferredLanguage'] ?? '',
                );
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
}
