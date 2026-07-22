# Déploiement production — Tadaaa

Provider-agnostique (Hetzner + Coolify, Infomaniak VPS, Compose SSH…).  
Stack : FrankenPHP (API) + PostgreSQL + SPA statique (`apps/web/dist`).

## Prérequis

- Domaine + 2 sous-domaines DNS `A`/`AAAA` :
  - `app.<domaine>` → front
  - `api.<domaine>` → API (+ `/mcp`)
- VPS ≥ 2–4 Go RAM, Docker + Compose
- Secrets **jamais** commités (`.env`, `.env.local`, JWT `.pem`)

## Build front

```bash
VITE_API_BASE_URL=https://api.example.com yarn build
```

Le build écrit dans `apps/web/dist`.

## Compose prod

```bash
cp .env.prod.example .env
# Éditer APP_SECRET, POSTGRES_PASSWORD, domaines, CORS, MCP_ALLOWED_HOSTS

docker compose -f compose.prod.yaml up -d --build
```

Fichiers :

| Fichier | Rôle |
|---------|------|
| [`compose.prod.yaml`](../compose.prod.yaml) | Edge Caddy + API + DB |
| [`deploy/Caddyfile.edge`](../deploy/Caddyfile.edge) | TLS, SPA `app.*`, reverse-proxy `api.*` |
| [`apps/api/.env.prod.dist`](../apps/api/.env.prod.dist) | Variables Symfony (référence) |

## Première install API

Dans le conteneur PHP :

```bash
docker compose -f compose.prod.yaml exec php bin/console doctrine:migrations:migrate --no-interaction

# Clés JWT (si volume vide)
docker compose -f compose.prod.yaml exec php sh -c \
  'mkdir -p config/jwt && openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 && openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem'

# Premier administrateur (compte actif)
docker compose -f compose.prod.yaml exec php bin/console app:user:create \
  you@example.com 'VotreMotDePasse' --admin --active
```

Inscriptions publiques : comptes créés en **`pending`** (`REGISTRATION_AUTO_APPROVE=0`).  
Valider / refuser / désactiver depuis **Config → Compte cloud** (utilisateur `ROLE_ADMIN`).

## Variables critiques

| Variable | Exemple |
|----------|---------|
| `APP_SECRET` | chaîne aléatoire longue |
| `DATABASE_URL` / `POSTGRES_*` | Postgres du compose |
| `CORS_ALLOW_ORIGIN` | `^https://app\.example\.com$` |
| `MCP_ALLOWED_HOSTS` | `api.example.com` |
| `DEFAULT_URI` | `https://api.example.com` |
| `REGISTRATION_AUTO_APPROVE` | `0` en prod |

## Smoke tests

1. `https://api.example.com/api/health` → `{"status":"ok"}`
2. Register sans admin → réponse `pending` (pas de JWT)
3. Login pending → 403
4. Admin approuve → login OK
5. Disable → API / MCP refusés
6. Front `https://app.example.com` + sync dataset
7. MCP Cursor : Bearer JWT ou PAT `tada_…` sur `https://api.example.com/mcp`

## Coolify (optionnel)

Créer une ressource **Docker Compose** pointant sur `compose.prod.yaml`, injecter les variables d’environnement, lier les domaines `app` / `api` (proxy SSL Coolify).  
Si Coolify termine TLS, adapter les ports publiés / `SERVER_NAME` selon la doc Coolify (souvent HTTP interne uniquement).

## Sauvegardes

- Volume Postgres + `pg_dump` périodique
- Snapshots VPS (Hetzner / Infomaniak)
- Conserver une copie hors serveur des clés JWT si rotation

## Dev local

Inchangé : voir [`.ops/README.md`](README.md) (`julien.test`, httpd devops).  
`REGISTRATION_AUTO_APPROVE=1` dans `apps/api/.env.local` pour ne pas bloquer le flux local.
