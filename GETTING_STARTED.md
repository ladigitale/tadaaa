# Bienvenue dans Tadaaa

Monorepo : front Concorde (`apps/web`) + API Symfony (`apps/api`).

```bash
yarn install
yarn dev
cp apps/api/.env.local.dist apps/api/.env.local
yarn api:up
yarn api:migrate
```

- Front : URL affichée par Vite (souvent `http://localhost:3000`)
- API Compose : `https://localhost:8443/api`

## Structure

```
apps/web/src/
├── main.ts
├── app/                 # application Tadaaa
└── …

apps/api/                # Symfony + API Platform
compose.yaml             # FrankenPHP + PostgreSQL
```

Le front reste **offline-first** (IndexedDB / mock-api). L’API cloud couvre auth, sync et MCP.

## Agents IA

```bash
yarn ai:sync
```

Voir `AGENTS.md`.
