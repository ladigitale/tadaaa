# Agents — Tadaaa (monorepo)

## Layout

| Path | Role |
|------|------|
| `apps/web/` | Concorde SPA (front) — `src/app/` paths relative to this package |
| `apps/api/` | Symfony 8 + API Platform |
| `apps/morseattack/` | MorseAttack PWA (offline light/sound) — part of this monorepo; see package `AGENTS.md` |
| `ai/starter/` | Agent overlay (repo root) |
| `.cursor/skills/` | Concorde / starter skills |

Root scripts: `yarn dev`, `yarn ai:sync`, `yarn api:up`. MorseAttack: `yarn morse:dev` / `yarn morse:test` (port 3100).

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

Guide pour les agents IA sur un projet **Concorde** (Lit + DataProvider).

## Skills / rules (après installation)

| Fichier | Rôle |
|---------|------|
| `.cursor/skills/concorde/SKILL.md` | Patterns framework |
| `.cursor/skills/concorde-imports/SKILL.md` | Imports courts |
| `.cursor/skills/concorde-scope/SKILL.md` | Scope + APIConfiguration |
| `.cursor/skills/concorde-theme/SKILL.md` | Design tokens sonic-theme |
| `.cursor/skills/concorde-menu/SKILL.md` | Navigation sonic-menu |
| `.cursor/skills/concorde-get-set-dp/SKILL.md` | Migration get/set/dp + DataProviderKey statique |
| `.cursor/rules/concorde.mdc` | Règles Cursor (patterns) |
| `.aiassistant/rules/concorde.md` | Règles JetBrains AI Assistant |

Installation :

```bash
node node_modules/@supersoniks/concorde/scripts/ai-init.mjs
```

Source : `@supersoniks/concorde/ai/`

## Imports dans ce dépôt (lib + doc)

Les chemins courts (`@supersoniks/concorde/list`, `/menu`, `/queue`, …) sont des **exports npm** pour les apps **externes**. Dans le repo Concorde (`src/docs`, `src/core`, démos), utiliser les chemins **réels** :

- Composants : `@supersoniks/concorde/core/components/…` ou import relatif (`../../core/components/functional/list/list`)
- Décorateurs : `@supersoniks/concorde/core/decorators/Subscriber` (ou `src/decorators.ts` via `@supersoniks/concorde/decorators` si résolu par l’alias Vite racine)
- `DataProviderKey` : `@supersoniks/concorde/core/utils/dataProviderKey`

Skill **`concorde-imports`** : section « Dans le dépôt Concorde ».

## Conventions impératives

- Toujours **DataProvider**, accès via **`get` / `set`**
- Pas de **`sonic-fetch`**, pas de **`PublisherManager`**
- Pas de `@onAssign` — **`@handle`** + `DataProviderKey`
- Pas de **`@bind`** sur les composants métier — **`@subscribe`** + `DataProviderKey<T, U>` (type + contraintes hôte `${…}`)
- Formulaires : **`formDataProvider`** + `name` sur `sonic-input`
- Listes : templates **Lit** (`.items`, `.separator`, `.noItems`, `.skeleton`) — pas de promotion des `<template>` HTML
- **Scope** (API/forms) ≠ **theme** (couleurs) — skills `concorde-scope` / `concorde-theme`

## Migration get / set / dp

Skill **`concorde-get-set-dp`** : chemins sans placeholder **`${…}`** / **`{$…}`** pour `get` / `set` / `dp` ; chemins JS évalués OK ; clés dynamiques → décorateurs, `dp(idRésolu)`, ou **`sub(clé)`** dans les templates Lit.

## Migration Subscriber / sonic-fetch

Skill **`concorde`** — section **« Piège migration Subscriber → LitElement »** : ne pas laisser des `@property` orphelines après retrait du mixin ; `@get` + `@subscribe` feuille ; `apiConfigKey` en modale ; sync des noms de props pour les `Endpoint` dynamiques.

## Documentation

Fichiers `.md` dans le package : `node_modules/@supersoniks/concorde/src/` (composants, décorateurs, getting-started).

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

- **`src/starter/`** — removable learning kit
- **`src/app/`** — minimal app after kit removal
- **`src/starter/routes/router.ts`** — generated (no hyphens in route folder names)

## Sync agent files

```bash
yarn ai:sync
```

Concorde source: `node_modules/@supersoniks/concorde/ai/`  
Starter overlay: `ai/starter/` (this repo).
