# Welcome to Tadaaa

Monorepo: Concorde front (`apps/web`) + Symfony API (`apps/api`).

```bash
yarn install
yarn dev
# optional API:
#   create apps/api/.env.local (gitignored) — see .ops/deploy.md
yarn api:up
yarn api:migrate
```

- Front: URL shown by Vite (often `http://localhost:3000`)
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
