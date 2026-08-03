---
name: starter-kit
description: >-
  create-concorde-ts-starter learning kit: src/starter/, sidebar sections,
  UI kit demo, mock API, concepts/demo/advanced routes. Removable for production.
---

# Starter kit — create-concorde-ts-starter

Landing + kit in **`src/starter/`** (removable). Future app: **`src/app/`**.

DataProvider access: **`src/utils/dataprovider.ts`** (`get`, `set`, `read`).

## Structure

```
src/starter/
├── routes/           # /, /concepts/*, /ui, /demo/*, /advanced/*
├── ui/               # UI kit demo component
├── concepts/         # dp.ts, demo components
├── demo/             # mock API, demo-*-templates
├── advanced/         # dynamic DataProviderKey
├── components/       # sidebar.ts, docs-shell.ts, starter-card.ts
└── init.ts
```

## Menu (sidebar)

| Section | Routes |
|---------|--------|
| **Data concepts** | `/concepts/dataprovider` … `/concepts/sub` |
| **UI kit** | `/ui` |
| **Scope** | `/concepts/scope` |
| **Functional patterns** | `/demo/get`, `/demo/list`, `/demo/queue` |
| **Advanced patterns** | `/advanced/dynamic` |

Source: `src/starter/components/sidebar.ts` — `CONCEPTS_SECTION`, `UI_SECTION`, `SCOPE_SECTION`, `DEMO_SECTION`, `ADVANCED_SECTION`.

Dev **↗ source** links when Cursor CLI is available (`cursor -r -g` via `/__starter/open`).

## Learning path

1. **UI kit** (`/ui`) — buttons, badges, cards; skill **`concorde-ui`**
2. DataProvider → **scope** (`/concepts/scope`) → DataProviderKey → Endpoint
3. @publish, @handle, formDataProvider, sub()
4. @get, sonic-list (Lit templates), sonic-queue (scroll + search)
5. Dynamic DataProviderKey (`/advanced/dynamic`)

## Key files

| Topic | File |
|-------|------|
| UI overview | `src/starter/ui/components/ui-kit-demo.ts` |
| Scope | `src/starter/routes/concepts/scope/page.ts`, `scope-callout.ts` |
| Concept keys | `src/starter/concepts/dp.ts` |
| Dynamic key | `src/starter/advanced/dp.ts` |
| List templates | `src/starter/demo/components/demo-list-templates.ts` |
| Queue templates | `src/starter/demo/components/demo-queue-templates.ts` |
| Mock API | `src/starter/demo/api/` |

## Remove the kit

See `src/starter/README.md` and `GETTING_STARTED.md`.
