# Agents — create-concorde-ts-starter

Starter layer (npm template). See base Concorde guidance in root `AGENTS.md`.

## Starter skills

| Skill | When |
|-------|------|
| `.cursor/skills/starter-kit/SKILL.md` | Kit `src/starter/`, menu, mock API |
| `.cursor/skills/concorde-ui/SKILL.md` | Pick UI components by use case |
| `.cursor/skills/concorde-scope/SKILL.md` | Inherited API / form / icon defaults (scope) |

## Architecture

- **`src/starter/`** — removable learning kit
- **`src/app/`** — minimal app after kit removal
- **`src/starter/routes/router.ts`** — generated (no hyphens in route folder names)

## Sync agent files

```bash
yarn ai:sync
```

Concorde source: `node_modules/@supersoniks/concorde/ai/`  
Starter overlay: `ai/starter/` (this repo).
