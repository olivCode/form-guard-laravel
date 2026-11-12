<?php

declare(strict_types=1);

namespace FormGuard\Laravel;

use Closure;
use Illuminate\Http\Request;

/** Preserve security signals before Laravel TrimStrings/ConvertEmptyStringsToNull. */
final class CaptureInput
{
    public function handle(Request $request, Closure $next): mixed
    {
        $length = filter_var($request->server('CONTENT_LENGTH'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($request->isMethod('POST') && $length !== false && $length <= config('form-guard.http.max_body_bytes')
            && ($request->request->has('form_id') || $request->request->has('csrf_token'))) {
            $request->attributes->set('form_guard.raw', $request->request->all());
        }
        return $next($request);
    }
}
