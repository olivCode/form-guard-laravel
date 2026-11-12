<?php

declare(strict_types=1);

namespace FormGuard\Laravel;

use FormGuard\Http\RequestRejected;
use FormGuard\Rejection;
use Illuminate\Http\Request;

final class FailureResponse
{
    public static function rejected(Rejection|RequestRejected $error, Request $request): mixed
    {
        $headers = ['Cache-Control' => 'no-store'];
        if ($error instanceof Rejection && $error->retryAfter > 0) {
            $headers['Retry-After'] = (string) $error->retryAfter;
        }
        if ($error->getCode() === 405) {
            $headers['Allow'] = 'GET, HEAD, POST';
        }
        $reason = $error instanceof Rejection ? $error->reason->value : $error->reason;
        return $request->expectsJson()
            ? response()->json(['message' => $error->getMessage(), 'reason' => $reason], $error->getCode(), $headers)
            : response($error->getMessage(), $error->getCode(), $headers)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function unavailable(Request $request): mixed
    {
        $message = preg_match('/^fr(?:[-_]|$)/i', (string) config('form-guard.locale')) === 1 ? 'La protection du formulaire est temporairement indisponible.' : 'Form protection is temporarily unavailable.';
        return $request->expectsJson() ? response()->json(['message' => $message], 503, ['Cache-Control' => 'no-store']) : response($message, 503)->header('Cache-Control', 'no-store');
    }
}
