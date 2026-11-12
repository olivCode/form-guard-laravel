<?php

declare(strict_types=1);

namespace FormGuard\Laravel\Tests;

use FormGuard\Laravel\FormGuardServiceProvider;
use FormGuard\Laravel\FormManager;
use FormGuard\Laravel\ClaimStore;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;

class EnforcedCsrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}

final class IntegrationTest extends TestCase
{
    private string $runtime;

    protected function getPackageProviders($app): array
    {
        return [FormGuardServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->runtime = dirname(__DIR__) . '/build/test-' . bin2hex(random_bytes(5));
        foreach (['sessions','cache'] as $directory) {
            mkdir($this->runtime . '/' . $directory, 0700, true);
        }
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('s', 32)));
        $app['config']->set('app.debug', false);
        $app['config']->set('session.driver', 'file');
        $app['config']->set('session.files', $this->runtime . '/sessions');
        $app['config']->set('session.cookie', 'form_guard_test');
        $app['config']->set('session.block_store', 'file');
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file', ['driver' => 'file','path' => $this->runtime . '/cache','lock_path' => $this->runtime . '/cache']);
        $app['config']->set('form-guard.directory', $this->runtime . '/protection');
        $app['config']->set('form-guard.http.origins', ['http://localhost']);
        $app['config']->set('form-guard.options', ['min_seconds' => 0]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', EnforcedCsrf::class])->group(function (Router $router): void {
            $router->get('/form', function (FormManager $forms) {
                return ['form' => app(\FormGuard\Guard::class)->publicForm($forms->issue(['email'])), 'token' => csrf_token()];
            })->formGuard();
            $router->post('/form', function (FormManager $forms) {
                return $forms->submit(fn (array $values): array => validator($values, ['email' => 'required|email'])->validate(), fn (array $data) => response()->json(['accepted' => true,'email' => $data['email']]));
            })->formGuard();
            $router->post('/slow', function (FormManager $forms) {
                return $forms->submit(function (array $data): array {
                    config(['form-guard.options.form_ttl' => 999999]);
                    sleep(2);
                    return $data;
                }, function (): array {
                    file_put_contents(config('form-guard.directory') . '/unexpected-action', 'executed');
                    return ['accepted' => true];
                });
            })->formGuard();
            $router->post('/scope-change', function (FormManager $forms) {
                config(['form-guard.scope' => 'changed-after-middleware']);
                return $forms->submit(fn (array $values): array => $values, fn (): array => ['accepted' => true]);
            })->formGuard();
            $router->get('/unlocked', fn (FormManager $forms) => $forms->issue(['email']))->middleware('form-guard');
            $router->get('/fields', function (FormManager $forms) {
                return view('form-guard::fields', ['form' => $forms->issue(['email'])]);
            })->formGuard();
        });
    }

    private function form(): array
    {
        $response = $this->get('/form', ['Accept' => 'application/json'])->assertOk();
        $this->withCookie('form_guard_test', $this->app['session']->driver()->getId());
        $form = $response->json('form');
        return ['form_id' => $form['id'],'csrf_token' => $form['csrf'],'_token' => $response->json('token'),$form['fields']['email'] => 'visitor@example.test', $form['fields']['honeypot'] => '', 'as_js' => '0','as_trap' => $form['js_trap'],'as_interactions' => '0'];
    }

    private function submit(array $data, array $headers = [])
    {
        $this->withServerVariables(['CONTENT_LENGTH' => (string) strlen(http_build_query($data)), 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        return $this->post('/form', $data, array_replace(['Accept' => 'application/json'], $headers));
    }

    public function test_native_form_and_replay(): void
    {
        $data = $this->form();
        $this->submit($data)->assertOk()->assertJson(['accepted' => true,'email' => 'visitor@example.test']);
        $this->submit($data)->assertStatus(403);
    }

    public function test_original_whitespace_honeypot_is_not_trimmed_away(): void
    {
        $data = $this->form();
        foreach ($data as $name => $value) {
            if (str_starts_with($name, 'hp_')) {
                $data[$name] = '   ';
            }
        }
        $this->submit($data)->assertStatus(422);
    }

    public function test_business_validation_does_not_consume_the_form(): void
    {
        $data = $this->form();
        $bad = $data;
        foreach ($bad as $name => $value) {
            if (str_starts_with($name, 'f_')) {
                $bad[$name] = 'invalid';
            }
        }
        $this->submit($bad)->assertStatus(422);
        $this->submit($data)->assertOk();
    }

    public function test_native_laravel_csrf_is_still_required(): void
    {
        $data = $this->form();
        unset($data['_token']);
        $this->submit($data)->assertStatus(419);
    }

    public function test_wrong_origins_are_rejected(): void
    {
        $this->submit($this->form(), ['Origin' => 'https://evil.test'])->assertStatus(403)->assertJson(['reason' => 'untrusted_origin']);
    }

    public function test_rate_limiting_counts_invalid_csrf_attempts(): void
    {
        $data = $this->form();
        unset($data['_token']);
        for ($i = 0;$i < 5;$i++) {
            $this->submit($data)->assertStatus(419);
        }
        $this->submit($data)->assertStatus(429)->assertHeader('Retry-After', '60');
    }

    public function test_unlocked_route_fails_closed(): void
    {
        $this->get('/unlocked')->assertStatus(503);
    }

    public function test_cookie_only_sessions_are_not_accepted(): void
    {
        config(['session.driver' => 'cookie']);
        $this->get('/form')->assertStatus(503);
    }

    public function test_blade_fields_and_cli(): void
    {
        $this->get('/fields')->assertOk()->assertSee('csrf_token')->assertSee('name="_token"', false)->assertSee('tabindex="-1"', false);
        $this->artisan('form-guard:check')->assertSuccessful();
        $this->artisan('form-guard:check', ['--prune' => true])->assertSuccessful();
    }

    public function test_reserved_action_cannot_run_again_even_with_a_stale_session_snapshot(): void
    {
        $data = $this->form();
        $snapshot = $this->app['session']->driver()->all();
        $this->submit($data)->assertOk();
        $session = $this->app['session']->driver();
        $session->replace($snapshot);
        $session->save();
        $this->submit($data)->assertStatus(403);
    }

    public function test_reservation_uniqueness_and_pruning(): void
    {
        $store = new ClaimStore($this->runtime . '/claims');
        $id = str_repeat('a', 32);
        self::assertTrue($store->claim('test', $id, time() + 7200));
        self::assertFalse($store->claim('test', $id, time() + 7200));
        self::assertSame(0, $store->prune());
        file_put_contents($this->runtime . '/claims/' . str_repeat('b', 64) . '.claim', (string)(time() - 1000));
        self::assertSame(1, $store->prune());
    }
    public function test_capacity_and_configuration_cache(): void
    {
        $store = new ClaimStore($this->runtime . '/small-claims', 1);
        self::assertTrue($store->claim('test', str_repeat('a', 32), time() + 7200));
        try {
            $store->claim('test', str_repeat('b', 32), time() + 7200);
            self::fail('Capacity was not enforced.');
        } catch (\FormGuard\Laravel\ProtectionUnavailable) {
            self::assertTrue(true);
        }
        try {
            $this->artisan('config:cache')->assertSuccessful();
        } finally {
            $this->artisan('config:clear')->assertSuccessful();
        }
    }

    public function test_original_expiry_is_enforced_after_slow_validation(): void
    {
        $data = $this->form();
        $session = $this->app['session']->driver();
        $forms = $session->get('form_guard.forms.contact');
        $forms[$data['form_id']]['expires'] = time() + 1;
        $session->put('form_guard.forms.contact', $forms);
        $session->save();
        $this->withServerVariables(['CONTENT_LENGTH' => (string) strlen(http_build_query($data)), 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        $this->post('/slow', $data, ['Accept' => 'application/json'])->assertStatus(403);
        self::assertFileDoesNotExist($this->runtime . '/protection/unexpected-action');
    }

    public function test_regional_french_locale_is_coherent_across_http_and_technical_errors(): void
    {
        config(['form-guard.locale' => 'fr-CA']);
        $this->submit($this->form(), ['Origin' => 'https://untrusted.test'])->assertStatus(403)->assertJsonPath('message', 'Cette requête ne peut pas être acceptée.');
        $this->get('/unlocked', ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('message', 'La protection du formulaire est temporairement indisponible.');
    }

    public function test_action_reservation_uses_the_verified_scope_snapshot(): void
    {
        $data = $this->form();
        $session = $this->app['session']->driver();
        $snapshot = $session->all();
        $this->withServerVariables(['CONTENT_LENGTH' => (string) strlen(http_build_query($data)), 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        $this->post('/scope-change', $data, ['Accept' => 'application/json'])->assertOk();
        config(['form-guard.scope' => 'contact']);
        $session = $this->app['session']->driver();
        $session->replace($snapshot);
        $session->save();
        $this->submit($data)->assertStatus(403);
    }

    public function test_default_origin_supports_an_application_in_a_subdirectory(): void
    {
        $oldServer = $_SERVER['APP_URL'] ?? null;
        $oldEnv = $_ENV['APP_URL'] ?? null;
        $oldProcess = getenv('APP_URL');
        try {
            $_SERVER['APP_URL'] = $_ENV['APP_URL'] = 'https://example.test:8443/client-area/';
            putenv('APP_URL=https://example.test:8443/client-area/');
            $settings = require dirname(__DIR__) . '/config/form-guard.php';
            self::assertSame(['https://example.test:8443'], $settings['http']['origins']);
            self::assertSame('https://example.test:8443/client-area/', env('APP_URL'));
        } finally {
            if ($oldServer === null) {
                unset($_SERVER['APP_URL']);
            } else {
                $_SERVER['APP_URL'] = $oldServer;
            }
            if ($oldEnv === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $oldEnv;
            }
            if ($oldProcess === false) {
                putenv('APP_URL');
            } else {
                putenv('APP_URL=' . $oldProcess);
            }
        }
    }

}
