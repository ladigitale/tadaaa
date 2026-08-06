# Sibling apps (own git repos; not versioned in tadaaa)

Clone optional sister SPAs into `apps/` for local / prod layout symmetry.

```bash
./scripts/clone-sibling-apps.sh
```

Configured siblings (edit the script to add remotes):

| Path | Env override | Default remote |
|------|--------------|----------------|
| `apps/morseattack` | `MORSEATTACK_GIT_URL` | (see script) |
| `apps/belts` | `BELTS_GIT_URL` | (see script) |

These directories are **gitignored** in the tadaaa repo.
