# Tadaaa — local / production ops

- **Production / VPS**: [deploy.md](deploy.md)
- **Install**: `bash scripts/install-prod.sh` (domain, admin, optional SMTP, quotas)
- **Update**: `bash scripts/update-prod.sh [--pull]` (merge new `.env` keys, rebuild, migrate; re-applies Glane/Belts edge if `GLANE_ROOT` / `BELTS_DIST` are set)
- **Local front (no Docker)**: `yarn start` from the repo root
- **Dev Docker Compose**: `yarn api:up` from the repo root (see README)
- **Scripts**: `scripts/` (JWT, front builds, helpers)

Personal devops hostnames (if any) are optional and not required to use the project.
