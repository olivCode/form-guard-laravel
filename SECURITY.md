[English](SECURITY.md) · [Français](SECURITY.fr.md)

# Security policy

The latest 1.0.x patch line receives security fixes. No contractual response SLA or independent audit is promised.

## Reporting a vulnerability

Use [GitHub private vulnerability reporting](https://github.com/olivCode/form-guard-laravel/security/advisories/new). Do not disclose exploit details in a public issue or pull request. If the issue also affects the other Form Guard package, mention it in one report.

Include the version, environment and a minimal synthetic reproduction. Never include SMTP credentials, keys, session tokens/cookies, real messages or visitor identifiers.

## Scope

Keep Laravel native CSRF, trusted-proxy configuration, HTTPS sessions, strict application validation and server body limits. Do not trust a raw Host/forwarded header for allowed origins or IP identity. File storage and reservation locks require a trusted private local filesystem on one host; distributed storage is outside this adapter's 1.0 guarantees. Keep durable reservations and sessions consistent during backup/recovery and do not clear them during deployment. Deleting all protection state cannot be detected as an old installation. A reservation prevents replay, not exactly-once remote delivery after a process crash. Return generic errors and never continue an action after protection failure.
