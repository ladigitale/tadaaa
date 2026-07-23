# Production deployment — Tadaaa

Provider-agnostic (Hetzner + Coolify, Infomaniak VPS, Compose over SSH…).  
Stack: FrankenPHP (API) + PostgreSQL + static SPA (`apps/web/dist`).

## Guided install (recommended)

On a fresh VPS (Ubuntu 24.04+, ports 80/443 open, DNS ready):

```bash
git clone https://github.com/ladigitale/tadaaa.git
cd tadaaa
bash scripts/install-prod.sh
```

You only enter: **base domain**, **email**, **admin email/password**.  
Everything else (secrets, CORS, MCP hosts, build, migrate, JWT, admin) is handled for you.

**Only env file versioned in the repo:** [`apps/api/.env`](../apps/api/.env) (generic template).  
The installer writes a root `.env` that stays gitignored.

---

## Manual setup

### Requirements

- Domain + two DNS `A`/`AAAA` records:
  - `app.<domain>` → front
  - `api.<domain>` → API (+ `/mcp`)
- VPS ≥ 2–4 GB RAM, Docker + Compose
- Secrets **never** committed

## Front build

```bash
VITE_API_BASE_URL=https://api.example.com yarn build
```

## Production Compose

Create a **root `.env`** (not versioned):

```bash
# .env — DO NOT COMMIT
APP_SERVER_NAME=app.example.com
API_SERVER_NAME=api.example.com
ACME_EMAIL=you@example.com

APP_SECRET=change-me-to-a-long-random-string
POSTGRES_DB=tada
POSTGRES_USER=app
POSTGRES_PASSWORD=change-me-db-password
POSTGRES_VERSION=16

CORS_ALLOW_ORIGIN=^https://app\.example\.com$
REGISTRATION_AUTO_APPROVE=0
MCP_ALLOWED_HOSTS=api.example.com

HTTP_PORT=80
HTTPS_PORT=443
HTTP3_PORT=443
```

Then:

```bash
VITE_API_BASE_URL=https://api.example.com yarn build
docker compose -f compose.prod.yaml up -d --build
```

| File | Role |
|------|------|
| [`compose.prod.yaml`](../compose.prod.yaml) | Edge Caddy + API + DB |
| [`deploy/Caddyfile.edge`](../deploy/Caddyfile.edge) | TLS, SPA `app.*`, reverse-proxy `api.*` |

Symfony overrides in production: Compose / Coolify environment variables  
(`APP_SECRET`, `DATABASE_URL`, `DEFAULT_URI`, `CORS_ALLOW_ORIGIN`, `MCP_ALLOWED_HOSTS`, …)  
— no `.env.prod` file in the repository.

## First API setup

```bash
docker compose -f compose.prod.yaml exec php bin/console doctrine:migrations:migrate --no-interaction

docker compose -f compose.prod.yaml exec php sh -c \
  'mkdir -p config/jwt && openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 && openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem'

docker compose -f compose.prod.yaml exec php bin/console app:user:create \
  you@example.com 'YourPassword' --admin --active
```

Sign-ups create **`pending`** accounts until an admin approves them (Config → Cloud account).

## Smoke tests

1. `https://api.example.com/api/health` → `{"status":"ok"}`
2. Register → `pending` (no JWT)
3. Pending login → 403; after approve → login OK
4. Disable → API / MCP cut off
5. Front + sync; MCP Bearer / PAT on `/mcp`

## Coolify (optional)

Docker Compose resource → `compose.prod.yaml`, inject env vars, bind `app` / `api`.

## Backups

Postgres volume + `pg_dump`; VPS snapshots; off-server copy of JWT keys.

## Local development

```bash
# apps/api/.env.local (gitignored)
APP_ENV=dev
REGISTRATION_AUTO_APPROVE=1
DATABASE_URL="postgresql://app:!ChangeMe!@database:5432/tada?serverVersion=16&charset=utf8"
CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'
MCP_ALLOWED_HOSTS=localhost,127.0.0.1
```

Then `yarn api:up` && `yarn api:migrate`.
