# Welcome to Tadaaa

Monorepo: Concorde front (`apps/web`) + Symfony API (`apps/api`).

```bash
yarn install
yarn start
# optional API (Docker):
#   create apps/api/.env.local (gitignored) — see .ops/deploy.md
#   yarn api:up && yarn api:migrate
```

- Front (no Docker): URL shown by Vite (often `http://localhost:3000`)
- Compose API: `https://localhost:8443/api`

## Layout

```
apps/web/src/
├── main.ts
├── app/                 # Tadaaa application
└── …

apps/api/                # Symfony + API Platform
compose.yaml             # FrankenPHP + PostgreSQL
```

The front stays **offline-first** (IndexedDB / mock-api). The cloud API covers auth, sync, and MCP.

## AI agents

```bash
yarn ai:sync
```

See `AGENTS.md`.


## Sister app — Artefacts

```bash
./scripts/clone-sibling-apps.sh   # apps/artifacts from ARTIFACTS_GIT_URL or atelier
yarn artifacts:dev                # http://localhost:3300
```

API env: `ARTIFACTS_PUBLIC_URL=http://localhost:3300` in `apps/api/.env`.
Migrate: `yarn api:migrate` (includes artefacts tables).
Seed: `docker compose exec php bin/console app:artifacts:seed-demo --email=you@example.com`
