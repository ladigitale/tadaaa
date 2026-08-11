# Optional Caddy site snippets for sister apps (imported by Caddyfile.edge).

Place `*.caddy` files here on the host. The edge mounts this directory read-only
at `/etc/caddy/cohost`. Do not nest Docker file mounts inside this volume.

Glane: copy from the Glane repo `deploy/tadaaa-cohost/glane.caddy`, or use the
checked-in `glane.caddy` in this folder after pull.
