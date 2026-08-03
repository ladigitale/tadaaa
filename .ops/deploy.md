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

You enter: **base domain**, **emails**, **admin password**, and optionally **Infomaniak SMTP**.  
Secrets, CORS/MCP hosts, quota defaults, build, migrate, JWT, and admin user are handled for you.

**Only env file versioned in the repo:** [`apps/api/.env`](../apps/api/.env) (generic template).  
The installer writes a root `.env` that stays gitignored.

### Updating an existing deploy

On the VPS (repo already cloned):

```bash
cd tadaaa
bash scripts/update-prod.sh --pull
```

This:

1. `git pull --ff-only` (if `--pull`)
2. **Merges missing `.env` keys** (mail, quotas, `APP_PUBLIC_URL`, …) without overwriting existing values
3. Rebuilds the SPA
4. `docker compose -f compose.prod.yaml up -d --build`
5. Runs Doctrine migrations

If `MAILER_DSN` is still `null://null`, set Infomaniak SMTP in `.env` then recreate PHP:

```bash
# edit .env — MAILER_DSN / MAIL_FROM / APP_PUBLIC_URL
docker compose -f compose.prod.yaml up -d php
```

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

# Mercure (realtime sync) — same secret for Caddy publisher/subscriber JWT
MERCURE_JWT_SECRET=change-me-mercure-jwt-secret
# Optional override (defaults to https://api…/.well-known/mercure)
# MERCURE_PUBLIC_URL=https://api.example.com/.well-known/mercure

# Web Push (VAPID) — required for server push to devices when the app is closed
# Generate inside the API container:
#   docker compose -f compose.prod.yaml exec php php -r \
#     'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:you@example.com

# Transactional mail (Infomaniak SMTP) — URL-encode user/password (@→%40, $→%24, &→%26…)
# MAILER_DSN=smtp://app%40example.com:url-encoded-pass@mail.infomaniak.com:587
# MAIL_FROM=app@example.com
MAILER_DSN=null://null
MAIL_FROM=app@tadaaa.space

# Front URL for email verification links (no trailing slash)
APP_PUBLIC_URL=https://app.example.com

# Free-tier quotas (storage 5 MiB; bandwidth shared across active users)
DEFAULT_STORAGE_QUOTA_BYTES=5242880
GLOBAL_MONTHLY_TRANSFER_BYTES=5368709120
FLOOR_PER_USER_MONTH_BYTES=52428800
CEIL_PER_USER_MONTH_BYTES=524288000
FLOOR_PER_USER_DAY_BYTES=2097152
CEIL_PER_USER_DAY_BYTES=26214400

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

Sign-ups create **`pending`** accounts until the user confirms their email. Admins can still approve, disable, reject, or delete accounts (optional personal message emailed to the user).

Free-tier quotas (override per user via admin API): **5 MiB** storage; bandwidth day/month shared from a global budget (`GLOBAL_MONTHLY_TRANSFER_BYTES`).

## Smoke tests

1. `https://api.example.com/api/health` → `{"status":"ok"}`
2. Register → `pending` + confirmation email (needs working `MAILER_DSN`)
3. Click link → `/account/verify` → active + JWT; pending login without verify → 403
4. Admin disable / reject / delete → email notice (optional personal message) → API / MCP cut off
5. Front + sync; MCP Bearer / PAT on `/mcp`
6. Mercure: open the same dataset on two browsers — edit on A → B pulls without refresh
7. Web Push: enable notifications in Config → Appearance (account connected); invite / check a task with the other browser closed
8. Quotas: Données → Sync shows storage + bandwidth gauges; over-quota sync → 413 / 429
9. Claude.ai custom connector → `https://api.example.com/mcp` → Connect (OAuth); allowlist Anthropic egress `160.79.104.0/21` if you firewall inbound

## Coolify (optional)

Docker Compose resource → `compose.prod.yaml`, inject env vars, bind `app` / `api`.

## Backups

Postgres volume + `pg_dump`; VPS snapshots; off-server copy of JWT keys.

## Local development

### Front only (no Docker)

```bash
yarn install
yarn start
```

SPA + IndexedDB / mock-api. No API container required.

### API (Docker Compose)

```bash
# apps/api/.env.local (gitignored)
APP_ENV=dev
REGISTRATION_AUTO_APPROVE=1
# Compose host "database". PHP outside Docker: 127.0.0.1 + published port (5433).
# DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5433/tada?serverVersion=16&charset=utf8"
DATABASE_URL="postgresql://app:!ChangeMe!@database:5432/tada?serverVersion=16&charset=utf8"
CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'
MCP_ALLOWED_HOSTS=localhost,127.0.0.1
MERCURE_PUBLIC_URL=https://localhost:8443/.well-known/mercure
MERCURE_JWT_SECRET="!ChangeThisMercureHubJWTSecretKey!"

# Infomaniak SMTP (optional in local; required for verify emails when AUTO_APPROVE=0)
# MAILER_DSN=smtp://app%40tadaaa.space:url-encoded-pass@mail.infomaniak.com:587
# MAIL_FROM=app@tadaaa.space
# APP_PUBLIC_URL=https://tada.julien.test
```

Then `yarn api:up` && `yarn api:migrate`.

Realtime sync uses the FrankenPHP Mercure hub (`/.well-known/mercure`). Without FrankenPHP (plain PHP-FPM), publishes are logged and ignored; the SPA still falls back to focus/online pull.
