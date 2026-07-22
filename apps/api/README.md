# Tadaaa API

Symfony 8 + API Platform 4 (FrankenPHP).

Phase 0 : scaffold + health check.  
Phase 1 : auth JWT + datasets.  
Phase 2 : sync offline.  
Phase 3 : MCP HTTP (`/mcp`) + PAT (`/api/access-tokens`).

## Local

Depuis la racine du monorepo :

```bash
docker compose up --build -d
```

- https://localhost:8443/api
- https://localhost:8443/api/health

`DATABASE_URL` pointe vers le service Compose `database`. Pour un PHP hors Docker, utiliser `127.0.0.1:5433`.
