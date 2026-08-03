---
name: concorde
description: >-
  Composants Web Concorde (Lit) : DataProvider, DataProviderKey, Endpoint,
  formDataProvider, sonic-list/sonic-queue, @subscribe/@handle/@get.
  Imports courts (@supersoniks/concorde/menu, /list, /utils…).
---

# Concorde — patterns et migrations

Source de vérité : fichiers `.md` dans `node_modules/@supersoniks/concorde/src/` (composants, décorateurs, docs).

**Imports** : toujours les chemins **les plus courts** — voir skill `concorde-imports` et section ci-dessous.

**Vocabulaire** : toujours **DataProvider**. Accès programmatique : **`get(chemin)`** (alias Concorde `dp`), **`set(chemin, valeur)`**. **Pas** `PublisherManager` ni `getDataProvider`.

## Imports (règle prioritaire)

Préférer `@supersoniks/concorde/menu` plutôt que `…/ui/menu`, `@supersoniks/concorde/list` plutôt que `…/functional/list`, etc.

Référence complète : skill **`concorde-imports`** (`.cursor/skills/concorde-imports/SKILL.md`).

## Interdits pour du **nouveau** code

- **Ne pas** étendre les mixins `Subscriber` / `Fetcher` sur des composants métier.
- **Ne pas** utiliser `data-bind`, `data-publish`, `data-subscribe` en HTML.
- **Ne pas** mettre `@input`, `@change` ou `.value` + assignation manuelle sur **`sonic-input`** quand `formDataProvider` + `name` suffit.
- **Ne pas** introduire `@onAssign` — préférer `@handle` + `DataProviderKey`.
- **Ne pas** utiliser **`sonic-fetch`** — préférer **`sonic-queue`** (+ filtre `formDataProvider`) ou **`@get`** + `Endpoint`.
- **Ne pas** importer **`PublisherManager`** — utiliser **`get` / `set`** (réexport Concorde ou wrapper projet).
- **Ne pas** promouvoir les templates HTML (`<template>`, `data-value`) pour **`sonic-list`** / **`sonic-queue`** — préférer les **propriétés Lit** (`.items`, `.separator`, `.noItems`, `.skeleton`).

## DataProvider

Store observable adressé par un chemin string :

```typescript
import { get, set } from "@supersoniks/concorde/utils";

set("myData", { count: 0 });
get("myData").count.set(1);
```

Attributs HTML courants :

| Attribut | Rôle |
|----------|------|
| `dataProvider` | Lie un composant au DataProvider à ce chemin |
| `formDataProvider` | Conteneur de formulaire — les champs `name` écrivent dedans |
| `dataFilterProvider` | Filtre lu par `sonic-queue` (relance les requêtes) |

## DataProviderKey

### Chemins statiques

```typescript
const counterKey = new DataProviderKey<{ count: number }>("myCounter");
counterKey.count.path; // "myCounter.count"
```

### Chemins dynamiques (placeholder)

Placeholder `${prop}` dans une **chaîne normale** (pas de backticks). Résolu depuis les propriétés du composant hôte ; ré-abonnement automatique quand elles changent.

```typescript
export const userKey = new DataProviderKey<User, { userIndex: number }>(
  "users.${userIndex}",
);

@property({ type: Number }) userIndex = 0;

@subscribe(userKey)
@state() user: User | null = null;
```

Même mécanisme pour `@handle`, `@publish`, `@bind`.

Doc : `src/docs/_misc/dataProviderKey.md`, `src/docs/_decorators/subscribe.md`, `handle.md`.

## Endpoint

```typescript
const users = new Endpoint<UsersResponse>("users?offset=$offset&limit=$limit");
users.path;
```

Utilisé avec `@get(endpoint, apiConfigKey)` ou `sonic-queue` / `sonic-list`.

## @publish / @subscribe

```typescript
@publish(counterKey.count)
@state()
count = 0;

@subscribe(counterKey.count)
@state()
subscribedCount = 0;
```

## formDataProvider

```html
<div formDataProvider="myForm">
  <sonic-input name="value" label="Texte"></sonic-input>
</div>
```

Initialisation : `set("myForm", { value: "" })`.

## sonic-queue — scroll infini + recherche

Pagination (`$offset` / `$limit`) **automatisée** avec `lazyload`.

```html
<div formDataProvider="myFilter">
  <sonic-input name="q" type="search" label="Rechercher"></sonic-input>
</div>
<sonic-queue
  lazyload
  dataFilterProvider="myFilter"
  dataProviderExpression="users?offset=$offset&limit=$limit"
  serviceurl="…"
  key="data"
  limit="4"
  .items=${renderUser}
></sonic-queue>
```

## Templates sonic-list / sonic-queue

Propriétés Lit (recommandé) :

| Propriété | Rôle |
|-----------|------|
| `.items(item, metadata)` | Rendu de chaque ligne |
| `.separator` | Entre chaque item (pas après le dernier) |
| `.noItems` | Liste vide |
| `.skeleton` | Chargement pendant un `fetch` |

`sonic-queue` transmet ces propriétés à chaque lot (`sonic-list` interne). `.noItems` ne s’applique qu’au **premier** lot.

## Décorateurs

| Besoin | API |
|--------|-----|
| Lire | `@subscribe(dpKey.field)` + `@state()` |
| Effet typé | `@handle(dpKey.a, …)` |
| API HTTP | `@get(Endpoint, apiConfigKey)` |
| Écriture classe (hors form) | `@publish` / `@bind` |

## Migrations courantes

| Ancien | Nouveau |
|--------|---------|
| `PublisherManager.get(…)` | `get(…)` ou `set(…)` |
| « publisher » | DataProvider |
| `sonic-input` + `@input` | `formDataProvider` + `name` |
| `sonic-fetch` | `sonic-queue` + filtre, ou `@get` |
| `extends Subscriber(LitElement)` | `LitElement` + `@subscribe` / `sub()` |
| `data-bind` HTML | `@subscribe` / `sub()` |
| `@onAssign` | `@handle` + `DataProviderKey` |
| Templates HTML list/queue | `.items`, `.separator`, `.noItems`, `.skeleton` |

## Recettes

- **Initialiser un DataProvider** : `set(key.path, { … })`.
- **Formulaire** : `formDataProvider` + `name` → `sub()` ou `@subscribe`.
- **Liste scroll infini** : `sonic-queue` + `lazyload` + `dataFilterProvider`.
- **Templates list/queue** : `.items`, `.separator`, `.noItems`, `.skeleton`.
- **Clé dynamique** : `"chemin.${prop}"` + `DataProviderKey<T, { prop: … }>`.
