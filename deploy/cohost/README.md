# Optional Caddy site snippets for sister apps (imported by Caddyfile.edge).

Place `*.caddy` files here on the host. The edge mounts this directory read-only
at `/etc/caddy/cohost`. Do not nest Docker file mounts inside this volume.

## Glane

Copy from the Glane repo `deploy/tadaaa-cohost/glane.caddy`, or use the checked-in
`glane.caddy` in this folder. Hostnames come from edge env (`GLANE_*`).

Set in Tadaaa `.env` so `scripts/update-prod.sh` re-applies the overlay after each update:

```bash
GLANE_ROOT=/root/glane
GLANE_APP_SERVER_NAME=glane.tadaaa.space
GLANE_API_SERVER_NAME=glane-api.tadaaa.space
WEB_NETWORK=web
```

## Belts

Use `belts.caddy` (hostname from `BELTS_SERVER_NAME`) and mount the built SPA via
`compose.prod.belts-cohost.yaml`.

```bash
BELTS_DIST=/opt/belt/dist
BELTS_SERVER_NAME=belts.tadaaa.space
```


## Artefacts

Use `artifacts.caddy` (hostname from `ARTIFACTS_SERVER_NAME`) and mount the built SPA via
`compose.prod.artifacts-cohost.yaml`.

```bash
ARTIFACTS_DIST=/opt/artifacts/dist
ARTIFACTS_SERVER_NAME=artifacts.tadaaa.space
ARTIFACTS_PUBLIC_URL=https://artifacts.tadaaa.space
# optional: ARTIFACTS_SRC=/path/to/checkout  ARTIFACTS_GIT_URL=https://github.com/ladigitale/artifacts.git
```

`scripts/update-prod.sh` builds Artefacts automatically when `ARTIFACTS_DIST` is set
(ephemeral `node:22` image, same as `apps/web`) and syncs into `ARTIFACTS_DIST`.
On the VPS: `bash scripts/update-prod.sh --pull` is enough — no host yarn / rsync from laptop.

CSP is strict (no `unsafe-eval`). Concorde 4.9.3 has no `sonic-jsonata` package export;
transforms in the artefact envelope are applied client-side with a bounded approach in the viewer.
