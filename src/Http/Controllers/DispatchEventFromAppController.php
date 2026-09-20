<?php

namespace Native\Mobile\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DispatchEventFromAppController
{
    public function __invoke(Request $request): JsonResponse
    {
        $traceId = $request->header('X-NativePHP-Trace-Id');
        $isTraced = $traceId !== null && $request->header('X-NativePHP-Event-Trace') === '1';
        $startedAt = $isTraced ? hrtime(true) : null;
        $event = $request->get('event');
        $payload = $request->get('payload', []);

        if (class_exists($event)) {
            $event = new $event(...$payload);
            event($event);

            return $this->response(true, $traceId, $startedAt);

        }

        return $this->response(false, $traceId, $startedAt);
    }

    private function response(bool $success, ?string $traceId, ?int $startedAt): JsonResponse
    {
        $response = response()->json([
            'success' => $success,
        ]);

        if ($traceId === null || $startedAt === null) {
            return $response;
        }

        $handlerUs = intdiv(hrtime(true) - $startedAt, 1_000);

        Log::debug('nativephp.event.trace', [
            'trace_id' => $traceId,
            'handler_us' => $handlerUs,
            'success' => $success,
        ]);

        return $response
            ->header('X-NativePHP-Trace-Id', $traceId)
            ->header('X-NativePHP-Event-Handler-Us', (string) $handlerUs);
    }
}
