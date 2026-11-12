<?php

declare(strict_types=1);

namespace FormGuard\Laravel;

use Closure;
use FormGuard\Guard;
use FormGuard\Http\RequestGuard;
use FormGuard\Http\RequestMetadata;
use FormGuard\Http\RequestRejected;
use FormGuard\Rejection;
use Illuminate\Http\Request;

final class ProtectForm
{
    public function __construct(private readonly Guard $guard, private readonly RequestGuard $http)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $route = $request->route();
            if (!$request->hasSession() || !$route || $route->locksFor() === null
                || !in_array(config('session.driver'), ['file', 'database', 'redis', 'memcached', 'dynamodb'], true)) {
                throw new \LogicException('Form Guard requires a persistent session and a session-blocked web route.');
            }
            $store = config('session.block_store') ?: config('cache.default');
            if (!in_array(config('cache.stores.' . $store . '.driver'), ['file', 'database', 'redis', 'memcached', 'dynamodb'], true)) {
                throw new \LogicException('Form Guard requires a persistent atomic session lock store.');
            }
            $request->attributes->set('form_guard.protected', true);
            if ($request->isMethod('POST')) {
                if ($request->attributes->has('form_guard.raw')) {
                    $request->request->replace($request->attributes->get('form_guard.raw'));
                }
                $this->guard->beginRequest($request->ip() ?? '', $request->userAgent() ?? '', $request->session()->getId());
                $length = filter_var($request->server('CONTENT_LENGTH'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $this->http->check(new RequestMetadata(
                    'POST',
                    $request->header('Content-Type', ''),
                    $length === false ? null : $length,
                    $request->header('Origin'),
                    $request->header('Referer'),
                    $request->header('Sec-Fetch-Site')
                ), $request->request->all(), count($request->allFiles()));
            } elseif (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
                throw new RequestRejected('method_not_allowed', 405);
            }
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store');
            return $response;
        } catch (Rejection|RequestRejected $error) {
            return FailureResponse::rejected($error, $request);
        } catch (ProtectionUnavailable|\LogicException|\FormGuard\Storage\StorageException|\FormGuard\Plugin\PluginExecutionException $error) {
            report($error);
            return FailureResponse::unavailable($request);
        }
    }
}
