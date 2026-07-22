# Tadaaa

**Todos offline-first**, sync cloud optionnel, et serveur **MCP** pour les agents IA.

Projet [La Digitale](https://ladigitale.dev) — monorepo front (Lit / Concorde) + API (Symfony 8 / API Platform).

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

---

## Fonctionnalités

- **Local d’abord** — tâches & tags en IndexedDB, utilisable sans serveur
- **Sync cloud** — jeux de données, merge incrémental, file d’attente offline
- **Compte sur invitation** — inscription en demande, validation / refus / révocation par un admin
- **MCP HTTP** — outils todos/tags pour Cursor & co (`/mcp`, JWT ou PAT `tada_…`)
- **P2P** — partage entre appareils (PeerJS)

## Stack

| Couche | Techno |
|--------|--------|
| Front | Lit, Concorde, Vite, IndexedDB |
| API | Symfony 8, API Platform, Lexik JWT, FrankenPHP |
| Données | PostgreSQL |
| Prod | Docker Compose (+ Caddy edge) |

```
apps/web/     SPA
apps/api/     API + MCP
compose.yaml  Dev API + Postgres
compose.prod.yaml
```

## Démarrage rapide (front seul)

```bash
yarn install
yarn dev
```

Ouvre l’URL Vite affichée (souvent `http://localhost:3000`).  
Les données restent locales (`/mock-api` + IndexedDB) tant que tu ne configures pas d’API cloud.

## API en local (Docker)

```bash
cp apps/api/.env.local.dist apps/api/.env.local
# Ajuste DATABASE_URL / CORS si besoin

yarn api:up
yarn api:migrate

# Clés JWT (une fois)
./.ops/scripts/install-jwt-keys.sh

# Premier admin (compte immédiatement actif)
docker compose exec php bin/console app:user:create \
  you@example.com 'VotreMotDePasse' --admin --active
```

- API : `https://localhost:8443/api`
- Health : `https://localhost:8443/api/health`
- MCP : `https://localhost:8443/mcp`

Inscriptions suivantes : statut `pending` jusqu’à approbation (Config → Compte cloud, compte admin).  
En local, `REGISTRATION_AUTO_APPROVE=1` dans `.env.local` accélère les tests.

## Production

Guide complet : [`.ops/deploy.md`](.ops/deploy.md)

```bash
cp .env.prod.example .env
# Renseigner secrets + domaines app.* / api.*

VITE_API_BASE_URL=https://api.example.com yarn build
docker compose -f compose.prod.yaml up -d --build
```

## Scripts utiles

| Commande | Rôle |
|----------|------|
| `yarn dev` | Front Vite |
| `yarn build` | Build SPA → `apps/web/dist` |
| `yarn api:up` / `api:down` | Compose API + DB |
| `yarn api:migrate` | Migrations Doctrine |
| `yarn api:logs` | Logs PHP / Postgres |

## Sécurité (rappel)

- Ne committez **jamais** `.env.local`, clés JWT (`.pem`), ni certificats
- Prod : `REGISTRATION_AUTO_APPROVE=0`, secrets hors Git
- Docs OpenAPI désactivées en `APP_ENV=prod`

## Licence

MIT — voir [LICENSE](LICENSE).

---

Fait avec ☕ pour [La Digitale](https://ladigitale.dev).
