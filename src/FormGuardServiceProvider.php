<?php

declare(strict_types=1);

namespace FormGuard\Laravel;

use FormGuard\Guard;
use FormGuard\Http\RequestGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

final class FormGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/form-guard.php', 'form-guard');
        $this->app->scoped(Guard::class, function ($app): Guard {
            $config = $app['config']->get('form-guard');
            $options = array_replace($config['options'], ['scope' => $config['scope'], 'runtime_dir' => $config['directory'] . '/limits', 'locale' => $config['locale']]);
            $configuration = \FormGuard\Configuration::fromArray(['profile' => $config['profile']]);
            $options = array_replace($configuration->options(), $options);
            $plugins = array_map(fn (string $class) => $app->make($class), $config['plugins']);
            return new Guard($options, plugins: $plugins);
        });
        $this->app->bind(ClaimStore::class, fn ($app): ClaimStore => new ClaimStore($app['config']->get('form-guard.directory') . '/claims'));
        $this->app->bind(RequestGuard::class, function ($app): RequestGuard {
            $config = $app['config']->get('form-guard.http');
            return new RequestGuard($config['origins'], $config['max_body_bytes'], $config['max_fields'], $config['max_depth'], $config['max_field_bytes'], $config['allow_missing_origin'], locale: $app['config']->get('form-guard.locale'));
        });
    }

    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('form-guard', ProtectForm::class);
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware(CaptureInput::class);
        $kernel->addToMiddlewarePriorityAfter(\Illuminate\Session\Middleware\StartSession::class, ProtectForm::class);
        Route::macro('formGuard', function (): Route {
            return $this->block(30, 10)->middleware('form-guard');
        });
        $this->callAfterResolving(\Illuminate\Contracts\Debug\ExceptionHandler::class, function ($handler): void {
            $handler->renderable(fn (\FormGuard\Rejection $error, \Illuminate\Http\Request $request) => FailureResponse::rejected($error, $request));
            $handler->renderable(fn (\FormGuard\Http\RequestRejected $error, \Illuminate\Http\Request $request) => FailureResponse::rejected($error, $request));
            $handler->renderable(fn (ProtectionUnavailable $error, \Illuminate\Http\Request $request) => FailureResponse::unavailable($request));
            $handler->renderable(fn (\FormGuard\Storage\StorageException $error, \Illuminate\Http\Request $request) => FailureResponse::unavailable($request));
            $handler->renderable(fn (\FormGuard\Plugin\PluginExecutionException $error, \Illuminate\Http\Request $request) => FailureResponse::unavailable($request));
        });
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'form-guard');
        $this->publishes([__DIR__ . '/../config/form-guard.php' => config_path('form-guard.php')], 'form-guard-config');
        $asset = dirname((new \ReflectionClass(Guard::class))->getFileName(), 2) . '/assets/form-guard.js';
        $this->publishes([$asset => public_path('vendor/form-guard/form-guard.js')], 'form-guard-assets');
        if ($this->app->runningInConsole()) {
            $this->commands([MaintenanceCommand::class]);
        }
    }
}
