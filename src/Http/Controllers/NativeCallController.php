<?php

namespace Native\Mobile\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NativeCallController
{
    /**
     * Bridge-function prefixes an app's lanes call among themselves and a web
     * view never legitimately does, so they are refused here.
     *
     * Ota.* downloads and stages an app update for next-boot apply — from a page
     * it would take an attacker-chosen URL and let a web view stage a malicious
     * bundle, so OTA is driven from app PHP only (in-process via nativephp_call,
     * which does not pass through this controller). Native also checks this, but
     * an app keeps the native project it installed until it reinstalls, so it
     * stops here too — and this gate ships with the composer package.
     */
    protected const INTERNAL_PREFIXES = ['Ota.'];

    public function __invoke(Request $request)
    {
        $method = $request->input('method');
        $params = $request->input('params', []);

        // Validate required parameters
        if (empty($method)) {
            return response()->json([
                'status' => 'error',
                'code' => 'MISSING_METHOD',
                'message' => 'Method parameter is required',
            ], 400);
        }

        if (is_string($method) && Str::startsWith($method, static::INTERNAL_PREFIXES)) {
            return response()->json([
                'status' => 'error',
                'code' => 'INTERNAL_METHOD',
                'message' => "Method '{$method}' is internal to NativePHP and cannot be called from a web view",
            ], 403);
        }

        // Check if nativephp_call function exists
        if (! function_exists('nativephp_call')) {
            return response()->json([
                'status' => 'error',
                'code' => 'FUNCTION_NOT_AVAILABLE',
                'message' => 'nativephp_call function is not available',
            ], 503);
        }

        // Check if nativephp_can exists and verify the method is registered
        if (function_exists('nativephp_can')) {
            if (! nativephp_can($method)) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'METHOD_NOT_FOUND',
                    'message' => "Method '{$method}' is not registered in the bridge registry",
                ], 404);
            }
        }

        try {
            // Encode params as JSON for nativephp_call
            $paramsJson = json_encode($params);

            // Call the native function
            $result = nativephp_call($method, $paramsJson);

            // Parse the JSON response from native
            $decodedResult = json_decode($result, true);

            // If decoding failed, return raw result
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'status' => 'success',
                    'data' => $result,
                ]);
            }

            // If the native side returned an error status, preserve it
            if (isset($decodedResult['status']) && $decodedResult['status'] === 'error') {
                return response()->json($decodedResult, 400);
            }

            // Return success with data
            return response()->json([
                'status' => 'success',
                'data' => $decodedResult,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'code' => 'EXECUTION_ERROR',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
