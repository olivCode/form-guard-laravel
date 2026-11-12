<?php

declare(strict_types=1);

namespace FormGuard\Laravel;

use FormGuard\Guard;
use FormGuard\Rejection;
use FormGuard\RejectionReason;
use Illuminate\Http\Request;

final class FormManager
{
    public function __construct(private readonly Request $request, private readonly Guard $guard, private readonly ClaimStore $claims)
    {
    }

    private function key(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $name)) {
            throw new \InvalidArgumentException('Invalid form name.');
        }
        if (!$this->request->attributes->get('form_guard.protected') || !$this->request->hasSession()) {
            throw new \LogicException('Use Form Guard on a session-blocked web route.');
        }
        return 'form_guard.forms.' . $name;
    }

    public function issue(array $fields, string $name = 'contact'): array
    {
        $key = $this->key($name);
        $forms = $this->request->session()->get($key, []);
        $form = $this->guard->issueForm($forms, $fields);
        $this->request->session()->put($key, $forms);
        return $form;
    }

    /** Validate business data, reserve/consume once, then invoke the action under the session lock. */
    public function submit(callable $validate, callable $action, string $name = 'contact'): mixed
    {
        $key = $this->key($name);
        if (!$this->request->isMethod('POST')) {
            throw new \LogicException('Submission requires POST.');
        }
        $forms = $this->request->session()->get($key, []);
        $verified = $this->guard->validateForm($this->request->request->all(), $forms);
        $values = [];
        $payload = $this->request->request->all();
        foreach ($verified['fields'] as $logical => $field) {
            if ($logical !== 'honeypot') {
                $values[$logical] = $payload[$field] ?? null;
            }
        }
        $data = $validate($values);
        if (!is_array($data)) {
            throw new \LogicException('The business validator must return validated data.');
        }
        $ttl = (int) config('form-guard.options.form_ttl', 7200);
        $expiry = $verified['expires'] ?? $verified['created'] + $ttl;
        if (time() > $expiry || !$this->claims->claim($verified['scope_id'], $verified['id'], $expiry)) {
            throw new Rejection(RejectionReason::FormUnavailable, preg_match('/^fr(?:[-_]|$)/i', (string) config('form-guard.locale')) === 1 ? 'Rechargez le formulaire avant de réessayer.' : 'Reload the form before trying again.');
        }
        $this->guard->consumeForm($forms, $verified['id']);
        $this->request->session()->put($key, $forms);
        // Persist consumption before a slow external action; keep the route session lock as well.
        $this->request->session()->save();
        return $action($data);
    }
}
