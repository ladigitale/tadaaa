# Security Policy

## Reporting a vulnerability

Please open a **private** security advisory on GitHub. Do not open a public issue for sensitive reports.

## Hardening checklist (operators)

- Keep `REGISTRATION_AUTO_APPROVE=0` in production (email verification required; `1` only for local/dev bypass)
- Set `MAILER_DSN`, `MAIL_FROM`, and `APP_PUBLIC_URL` so confirmation / moderation emails work
- Tune `DEFAULT_STORAGE_QUOTA_BYTES` and bandwidth env vars (`GLOBAL_MONTHLY_TRANSFER_BYTES`, floor/ceil) to match VPS capacity
- Store `APP_SECRET`, database passwords, JWT keys, and `MERCURE_JWT_SECRET` outside Git
- Restrict `CORS_ALLOW_ORIGIN` and `MCP_ALLOWED_HOSTS` to your real domains
- Rotate PATs (`tada_…`) and disable compromised accounts via the admin UI (optional personal message is emailed)
- OAuth MCP tokens (`tdoa_` / `tdor_`) are per Claude connection; revoke via account disable or `/oauth/revoke`
- Google Calendar refresh tokens are encrypted at rest (`APP_SECRET`); set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` and register redirect `{DEFAULT_URI}/api/google-calendar/callback`. Disconnect revokes the Google grant.
- Dataset invite links are single-use and expire (7 days); prefer short-lived shares and remove members when access ends
- Mercure subscribe credentials are scoped to datasets the user can read — keep the hub JWT secret strong and shared only with the edge proxy
