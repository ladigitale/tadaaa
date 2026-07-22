# Concorde — patterns agent

Référence doc : fichiers `.md` dans `node_modules/@supersoniks/concorde/src/`.

## Imports (chemins courts)

Préférer `@supersoniks/concorde/menu` plutôt que `ui/menu`, `@supersoniks/concorde/list` plutôt que `functional/list`, etc.

Composants : `/menu`, `/menu-item`, `/divider`, `/button`, `/input`, `/theme`, `/list`, `/queue`, `/router`  
Utilitaires : `/decorators`, `/directives`, `/utils`, `/utils/endpoint`, `/utils/api`, `/dataProviderKey`

## Vocabulaire

- **DataProvider** (jamais « publisher »)
- Accès : **get / set** — pas PublisherManager

## Interdits (nouveau code)

- sonic-fetch → sonic-queue + filtre, ou @get
- @onAssign → @handle + DataProviderKey
- data-bind HTML → @subscribe / sub()
- sonic-input + @input → formDataProvider + name
- Templates HTML list/queue → .items, .separator, .noItems, .skeleton
- Chemins longs ui/…, functional/…, core/… quand un alias racine existe

## DataProviderKey dynamique

Placeholder `${prop}` dans une chaîne normale : `"users.${userIndex}"`.

## Navigation

sonic-menu + sonic-menu-item + sonic-divider. pushstate + autoActive="strict".

Pas de tirets dans les noms de dossiers routes.
