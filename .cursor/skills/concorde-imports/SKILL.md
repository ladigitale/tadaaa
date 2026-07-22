# Imports Concorde — chemins courts

Toujours préférer le **chemin export le plus court** documenté dans `package.json` de `@supersoniks/concorde`.  
Éviter les chemins `ui/…`, `functional/…`, `core/…` quand un alias racine existe.

## Composants UI

| Composant | ✅ Préférer | ❌ Éviter |
|-----------|------------|----------|
| Menu | `@supersoniks/concorde/menu` | `…/ui/menu` |
| Menu item | `@supersoniks/concorde/menu-item` | `…/ui/menu-item` |
| Divider | `@supersoniks/concorde/divider` | `…/ui/divider` |
| Button | `@supersoniks/concorde/button` | `…/ui/button` |
| Theme | `@supersoniks/concorde/theme` | `…/ui/theme` |
| Input | `@supersoniks/concorde/input` | `…/ui/form/input` |
| Select | `@supersoniks/concorde/select` | `…/ui/form/select` |
| Checkbox | `@supersoniks/concorde/checkbox` | `…/ui/form/checkbox` |

## Composants fonctionnels

| Composant | ✅ Préférer | ❌ Éviter |
|-----------|------------|----------|
| List | `@supersoniks/concorde/list` | `…/functional/list` |
| Queue | `@supersoniks/concorde/queue` | `…/functional/queue` |
| Router | `@supersoniks/concorde/router` | `…/core/components/functional/router/router` |
| Fetch | `@supersoniks/concorde/fetch` | `…/functional/fetch` |
| Value | `@supersoniks/concorde/value` | `…/functional/value` |

## Utilitaires & types

| Besoin | ✅ Préférer | ❌ Éviter |
|--------|------------|----------|
| Décorateurs | `@supersoniks/concorde/decorators` | chemins `…/core/decorators/…` |
| Directives (`sub`) | `@supersoniks/concorde/directives` | — |
| `get` / `set` / `dp` | `@supersoniks/concorde/utils` | `PublisherManager` |
| `DataProviderKey` | `@supersoniks/concorde/dataProviderKey` | `…/core/utils/dataProviderKey` |
| `Endpoint` | `@supersoniks/concorde/utils/endpoint` | `…/core/utils/endpoint` |
| `APIConfiguration` | `@supersoniks/concorde/utils/api` | `…/core/utils/api` |
| Vite config | `@supersoniks/concorde/vite-config` | — |

## Exemples

```typescript
import "@supersoniks/concorde/menu";
import "@supersoniks/concorde/menu-item";
import "@supersoniks/concorde/divider";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/theme";
import "@supersoniks/concorde/input";
import "@supersoniks/concorde/list";
import "@supersoniks/concorde/queue";
import "@supersoniks/concorde/router";

import { subscribe, handle, get } from "@supersoniks/concorde/decorators";
import { sub } from "@supersoniks/concorde/directives";
import { get, set } from "@supersoniks/concorde/utils";
import { DataProviderKey } from "@supersoniks/concorde/dataProviderKey";
import { Endpoint } from "@supersoniks/concorde/utils/endpoint";
import type { APIConfiguration } from "@supersoniks/concorde/utils/api";
```

Side-effect imports (enregistrement custom elements) : toujours le chemin **composant** court ci-dessus.
