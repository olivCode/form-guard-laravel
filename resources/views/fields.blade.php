@csrf
<input type="hidden" name="form_id" value="{{ $form['id'] }}">
<input type="hidden" name="csrf_token" value="{{ $form['csrf'] }}">
{!! app(\FormGuard\Guard::class)->renderSignals($form) !!}
<div hidden aria-hidden="true">
    <label>Leave empty <input type="text" name="{{ $form['fields']['honeypot'] }}" value="" tabindex="-1" autocomplete="off"></label>
</div>
