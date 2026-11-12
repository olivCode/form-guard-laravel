[English](README.md) · [Français](README.fr.md)

# Form Guard Laravel

![PHP](https://img.shields.io/badge/PHP-%5E8.3-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Laravel adapter for the shared Form Guard PHP engine: container/provider integration, protected web routes, session forms, Blade fields, JSON/HTML errors and maintenance. The core is a Composer dependency, not a fork. The 1.0 scope is PHP + Laravel 13; other Laravel majors and language ports are not claimed as tested support.

## Installation

Install the package from Packagist:

```sh
composer require olivcode/form-guard-laravel:^1.0
php artisan vendor:publish --tag=form-guard-config
php artisan vendor:publish --tag=form-guard-assets
```

Laravel uses Composer; the no-Composer installation applies to the standalone PHP core.

Set `APP_URL` to the real application URL, including its subdirectory if any. The default allowed origin extracts only its trusted scheme/host/port; override `form-guard.http.origins` when needed. `directory` must be an absolute persistent private path, outside the web root. Configure server body limits as well as the package's 16 KiB policy. Defaults are a three-second minimum and the core Standard profile. `options` accepts core flat options and overrides the chosen profile; scope/directory/locale come from the adapter's dedicated settings. `plugins` contains container-resolvable class names, never instances/closures in cached configuration. Keep `config:cache` enabled if desired; no request object is retained in a singleton.

## Form integration

Use `.formGuard()` on **both** GET and POST routes. It adds the middleware and route session blocking. Existing `routes/web.php` routes already belong to `web`; avoid adding the group twice there. Example for package/custom routes:

```php
use FormGuard\Laravel\FormManager;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/contact', function (FormManager $forms) {
        return view('contact', ['form' => $forms->issue(['email', 'message'])]);
    })->formGuard();

    Route::post('/contact', function (FormManager $forms) {
        return $forms->submit(
            fn (array $values): array => validator($values, [
                'email' => 'required|email|max:254',
                'message' => 'required|string|max:4000',
            ])->validate(),
            function (array $data) {
                // Perform your application action here.
                return redirect('/contact')->with('status', 'Thank you');
            },
        );
    })->formGuard();
});
```

```blade
<form method="post" action="/contact" data-antispam>
    @include('form-guard::fields', ['form' => $form])
    <label for="email">Email</label>
    <input id="email" type="email" name="{{ $form['fields']['email'] }}" autocomplete="email" required>
    <label for="message">Message</label>
    <textarea id="message" name="{{ $form['fields']['message'] }}" required></textarea>
    <button type="submit">Send</button>
</form>
<script src="/vendor/form-guard/form-guard.js" defer></script>
```

The Blade include emits both Laravel's native CSRF token and Form Guard tokens/signals/honeypot. They are separate checks; never disable Laravel CSRF to integrate this package. The optional JavaScript helper is a copied public asset; no Node build is needed. Keep all visible controls labelled and provide accessible error feedback.

`issue(array $fields, string $name = 'contact')` stores the form in the server session. `submit(callable $validate, callable $action, string $name = 'contact')` maps randomized fields, verifies protection, calls a **side-effect-free** business validator returning an array, reserves/consumes the token, persists the session, then invokes the action. Use the same server-defined name on GET/POST. Laravel validation failures do not consume the form; an executed action does. Do not send mail/write an order inside the validator. Database/external action retries need application-level idempotency/transactions; this library cannot guarantee exactly-once remote delivery.

## Sessions, replay and failures

Use a persistent session driver and atomic lock store (`file`, `database`, `redis`, `memcached` or `dynamodb`). Cookie-only/array sessions or unlocked routes fail closed. The file backend and action reservations require one host with reliable local locking; a Redis session alone does not turn the limiter into a distributed backend. Concurrent PHP workers on that host are supported.

The middleware is ordered after StartSession and before native CSRF, so invalid CSRF attempts also count. A bounded input snapshot precedes Laravel's global trimming/null conversion, and original values are restored only on protected routes. This prevents a whitespace honeypot being erased; validate/normalize business values yourself.

A dedicated private `claims/` directory stores action reservations independently of the session lease, so stale sessions/expired lock leases cannot cause the same action to run twice. Reservations are durable before the action, have bounded capacity (10,000 by default), and are pruned after form expiry plus a safety margin. Keep `limits/`, `claims/` and sessions persistent. Never clear action reservations to perform an ordinary deployment. Storage errors, invalid plugin composition and technical plugin failures stop processing. JSON callers receive status/reason; rate limits include `Retry-After`; protected responses use `Cache-Control: no-store`.

## Maintenance

```sh
php artisan form-guard:check
php artisan form-guard:check --prune
```

Schedule `form-guard:check --prune` regularly, for example hourly. Check storage permissions/Windows ACLs for PHP and CLI users. Preserve limiter key/state together; do not change the scope during a routine update. Back up reservations consistently with sessions when recovery must retain replay protection. See [SECURITY](SECURITY.md).

## Development and verification

```sh
composer install
composer check
composer test
composer test:distribution
composer audit --locked
```

The core is installed from Packagist, as in a consuming application. CI checks PHP 8.3/8.4/8.5 on Linux and Windows. Tests use synthetic data without a production provider.
