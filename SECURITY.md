# Security Policy

## Reporting a vulnerability

Please open a **private** security advisory on GitHub. Do not open a public issue for sensitive reports.

## Hardening checklist (operators)

- Keep `REGISTRATION_AUTO_APPROVE=0` in production
- Store `APP_SECRET`, database passwords, JWT keys, and `MERCURE_JWT_SECRET` outside Git
- Restrict `CORS_ALLOW_ORIGIN` and `MCP_ALLOWED_HOSTS` to your real domains
- Rotate PATs (`tada_…`) and disable compromised accounts via the admin UI
- OAuth MCP tokens (`tdoa_` / `tdor_`) are per Claude connection; revoke via account disable or `/oauth/revoke`
- Dataset invite links are single-use and expire (7 days); prefer short-lived shares and remove members when access ends
- Mercure subscribe credentials are scoped to datasets the user can read — keep the hub JWT secret strong and shared only with the edge proxy
