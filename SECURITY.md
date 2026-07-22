# Security Policy

## Reporting a vulnerability

Please open a **private** security advisory on GitHub, or contact the maintainers of [La Digitale](https://ladigitale.dev) — do not open a public issue for sensitive reports.

## Hardening checklist (operators)

- Keep `REGISTRATION_AUTO_APPROVE=0` in production
- Store `APP_SECRET`, database passwords, and JWT keys outside Git
- Restrict `CORS_ALLOW_ORIGIN` and `MCP_ALLOWED_HOSTS` to your real domains
- Rotate PATs (`tada_…`) and disable compromised accounts via admin UI
