# Tadaaa

**Offline-first todos**, optional cloud sync, and an **MCP** server for AI agents.

Lit / Concorde front + Symfony 8 / API Platform API.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

---

## Features

- **Local first** — tasks & tags in IndexedDB, usable without a server
- **Cloud sync** — datasets, incremental merge, offline outbox; optional **Mercure** push for near-realtime updates
- **Dataset sharing** — single-use invite links (7 days), reader / writer roles, member management
- **Invite-style accounts** — registration as a request; admin approve / reject / revoke
- **MCP HTTP** — todo/tag/link-detector tools for Cursor & Claude (`/mcp`; PAT `tada_…` or OAuth for Claude.ai)
- **Link detectors** — turn ticket tokens (e.g. `RM-12345`) into links; sync with the cloud account
- **Calendar & due dates** — list/calendar views; optional web notifications for invites and due dates
- **PWA** — installable SPA (manifest + icons)
- **P2P** — share a dataset between devices (PeerJS)
- **i18n** — English / French UI wording

## Stack

| Layer | Tech |
|-------|------|
| Front | Lit, Concorde, Vite, IndexedDB |
| API | Symfony 8, API Platform, Lexik JWT, FrankenPHP |
| Data | PostgreSQL |
| Prod | Docker Compose (+ Caddy edge) |

```
apps/web/     SPA
apps/api/     API + MCP
compose.yaml  Dev API + Postgres
compose.prod.yaml
```

## Quick start (front only)

```bash
yarn install
yarn dev
```

Open the URL Vite prints (often `http://localhost:3000`).  
Data stays local (`/mock-api` + IndexedDB) until you configure a cloud API.

## Local API (Docker)

```bash
# Create apps/api/.env.local (gitignored), e.g.:
#   REGISTRATION_AUTO_APPROVE=1
#   DATABASE_URL=postgresql://app:!ChangeMe!@database:5432/tada?serverVersion=16&charset=utf8

yarn api:up
yarn api:migrate

# JWT keys (once)
./.ops/scripts/install-jwt-keys.sh

# First admin (immediately active)
docker compose exec php bin/console app:user:create \
  you@example.com 'YourPassword' --admin --active
```

- API: `https://localhost:8443/api`
- Health: `https://localhost:8443/api/health`
- MCP: `https://localhost:8443/mcp`
- OAuth discovery: `https://localhost:8443/.well-known/oauth-authorization-server`  
  (Claude.ai custom connector → URL `/mcp` → Connect; Cursor still uses PAT)

Further sign-ups stay `pending` until an admin approves them (Config → Cloud account).  
Locally, `REGISTRATION_AUTO_APPROVE=1` in `.env.local` speeds up testing.

## Production

**Guided install (recommended on a VPS):**

```bash
git clone https://github.com/ladigitale/tadaaa.git
cd tadaaa
bash scripts/install-prod.sh
```

The script asks only for:

1. Base domain (uses `app.` / `api.` subdomains)
2. Email (Let’s Encrypt)
3. Admin email + password

It generates secrets, builds the front, starts Compose, runs migrations + JWT keys, and creates the admin user.

Manual steps / details: [`.ops/deploy.md`](.ops/deploy.md)

## Useful scripts

| Command | Purpose |
|---------|---------|
| `yarn dev` | Front Vite |
| `yarn build` | SPA build → `apps/web/dist` |
| `yarn api:up` / `api:down` | API + DB Compose |
| `yarn api:migrate` | Doctrine migrations |
| `yarn api:logs` | PHP / Postgres logs |

## Security notes

- **Only** `apps/api/.env` (placeholders) is versioned — no other `.env*` files
- Never commit `.env.local`, JWT keys (`.pem`), or certificates
- Production: `REGISTRATION_AUTO_APPROVE=0`, strong `APP_SECRET` / DB / `MERCURE_JWT_SECRET`, secrets outside Git
- OpenAPI docs disabled when `APP_ENV=prod`

## License

MIT — see [LICENSE](LICENSE).

---

Built with ☕.
