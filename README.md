# Tadaaa

**Offline-first todos**, optional cloud sync, and an **MCP** server for AI agents.

Lit / Concorde front + Symfony 8 / API Platform API.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

---

## Features

- **Local first** — tasks & tags in IndexedDB, usable without a server
- **Cloud sync** — datasets, incremental merge, offline outbox; optional **Mercure** push for near-realtime updates
- **Dataset sharing** — single-use invite links (7 days), reader / writer roles, member management
- **Self-serve accounts** — email confirmation; admin can disable / reject / delete (optional personal note emailed)
- **MCP HTTP** — todo/tag/link-detector tools for Cursor & Claude (`/mcp`; PAT `tada_…` or OAuth for Claude.ai)
- **Link detectors** — turn ticket tokens (e.g. `RM-12345`) into links; sync with the cloud account
- **Calendar & due dates** — list/calendar views; optional web notifications for invites and due dates
- **Simple recurrence** — daily / weekly / monthly; completing a task spawns the next occurrence (dates shifted)
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

## Quick start (no Docker)

```bash
yarn install
yarn start
```

Open the URL Vite prints (often `http://localhost:3000`).  
Data stays local (`/mock-api` + IndexedDB) until you configure a cloud API.  
`yarn start` and `yarn dev` are the same — front only, nothing else to launch.

## Local API (optional, Docker)

```bash
# Create apps/api/.env.local (gitignored), e.g.:
#   REGISTRATION_AUTO_APPROVE=1
#   # Compose host "database". PHP outside Docker → 127.0.0.1:5433
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

Further sign-ups stay `pending` until the user confirms their email.  
Locally, `REGISTRATION_AUTO_APPROVE=1` in `.env.local` skips verification for faster testing.

## Production

**Guided install (recommended on a VPS):**

```bash
git clone https://github.com/ladigitale/tadaaa.git
cd tadaaa
bash scripts/install-prod.sh
```

The script asks for:

1. Base domain (uses `app.` / `api.` subdomains)
2. Email (Let’s Encrypt)
3. Admin email + password
4. Optional Infomaniak SMTP (verification / moderation emails)

It generates secrets, writes quota defaults, builds the front, starts Compose, runs migrations + JWT keys, and creates the admin user.

**Updates on an existing VPS:**

```bash
bash scripts/update-prod.sh --pull
```

Merges any new `.env` keys (mail, quotas, …), rebuilds, migrates. Details: [`.ops/deploy.md`](.ops/deploy.md)

## Useful scripts

| Command | Purpose |
|---------|---------|
| `yarn start` / `yarn dev` | Front Vite (no Docker) |
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
