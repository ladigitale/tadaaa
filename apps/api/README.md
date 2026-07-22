# Tadaaa API

Symfony 8 + API Platform 4 (FrankenPHP).

- Phase 0: scaffold + health check
- Phase 1: JWT auth + datasets
- Phase 2: offline sync
- Phase 3: MCP HTTP (`/mcp`) + PAT (`/api/access-tokens`)

## Local

From the monorepo root:

```bash
docker compose up --build -d
```

- https://localhost:8443/api
- https://localhost:8443/api/health

`DATABASE_URL` points at the Compose `database` service. For PHP outside Docker, use `127.0.0.1:5433`.
