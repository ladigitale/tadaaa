# Agents — Tadaaa (monorepo)

## Layout

| Path | Role |
|------|------|
| `apps/web/` | Concorde SPA (front) — `src/app/` paths relative to this package |
| `apps/api/` | Symfony 8 + API Platform |
| `ai/starter/` | Agent overlay (repo root) |
| `.cursor/skills/` | Concorde / starter skills |

Root scripts: `yarn dev`, `yarn ai:sync`, `yarn api:up`.

---

# Agents — Concorde + starter

Guide for AI agents on the **create-concorde-ts-starter** template.

## Skills / rules

| File | Role |
|------|------|
| `.cursor/skills/concorde/SKILL.md` | Concorde framework patterns |
| `.cursor/skills/concorde-imports/SKILL.md` | Short imports (menu, list, utils…) |
| `.cursor/skills/concorde-menu/SKILL.md` | sonic-menu navigation |
| `.cursor/skills/starter-kit/SKILL.md` | Learning kit `src/starter/` |
| `.cursor/skills/concorde-ui/SKILL.md` | UI components by use case |
| `.cursor/rules/*.mdc` | Cursor rules |
| `.aiassistant/rules/concorde.md` | JetBrains AI Assistant rules |

Regenerate after changes: `yarn ai:sync`  
Sources: `apps/web/node_modules/@supersoniks/concorde/ai/` + overlay `ai/starter/`.

## Hard requirements

- Always **DataProvider**, access via **`get` / `set`**
- No **`sonic-fetch`**, no **`PublisherManager`**
- No `@onAssign` — **`@handle`** + `DataProviderKey`
- Forms: **`formDataProvider`** + `name` on `sonic-input`
- Lists: **Lit** templates (`.items`, `.separator`, `.noItems`, `.skeleton`)
- **Imports**: short paths (`@supersoniks/concorde/menu`, `/list`, `/utils/endpoint`) — skill `concorde-imports`

## Starter architecture

- **`apps/web/src/starter/`** — removable learning kit
- **`apps/web/src/app/`** — minimal app after kit removal
- **`apps/web/src/app/routes/router.ts`** — generated (no hyphens in route folder names)

## Concorde documentation

`.md` files in the installed package: `apps/web/node_modules/@supersoniks/concorde/src/` (UI components, decorators, getting-started).

---

<!-- concorde-ai -->

# Agents — Concorde

Guide for AI agents on a **Concorde** project (Lit + DataProvider).

## Skills / rules (after install)

| File | Role |
|------|------|
| `.cursor/skills/concorde/SKILL.md` | Framework patterns |
| `.cursor/skills/concorde-menu/SKILL.md` | sonic-menu navigation |
| `.cursor/rules/concorde.mdc` | Cursor rules (patterns) |
| `.aiassistant/rules/concorde.md` | JetBrains AI Assistant rules |

Install: `node node_modules/@supersoniks/concorde/scripts/ai-init.mjs`  
Source: `@supersoniks/concorde/ai/`

## Hard requirements

- Always **DataProvider**, access via **`get` / `set`**
- No **`sonic-fetch`**, no **`PublisherManager`**
- No `@onAssign` — **`@handle`** + `DataProviderKey`
- Forms: **`formDataProvider`** + `name` on `sonic-input`
- Lists: **Lit** templates (`.items`, `.separator`, `.noItems`, `.skeleton`) — do not promote HTML `<template>`s

## Documentation

`.md` files in the package: `node_modules/@supersoniks/concorde/src/` (components, decorators, getting-started).

---

# Agents — create-concorde-ts-starter

Starter layer (npm template). See base Concorde guidance in root `AGENTS.md`.

## Starter skills

| Skill | When |
|-------|------|
| `.cursor/skills/starter-kit/SKILL.md` | Kit `src/starter/`, menu, mock API |
| `.cursor/skills/concorde-ui/SKILL.md` | Pick UI components by use case |
| `.cursor/skills/concorde-scope/SKILL.md` | Inherited API / form / icon defaults (scope) |

## Architecture

- **`apps/web/src/starter/`** — removable learning kit
- **`apps/web/src/app/`** — minimal app after kit removal
- **`apps/web/src/app/routes/router.ts`** — generated (no hyphens in route folder names)

## Sync agent files

```bash
yarn ai:sync
```

Concorde source: `apps/web/node_modules/@supersoniks/concorde/ai/`  
Starter overlay: `ai/starter/` (this repo).
