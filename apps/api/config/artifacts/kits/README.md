# Kits d'artefacts

Un kit est un document `artifacts/1` squelette accompagné de paramètres. L'agent de l'atelier (`start_from_kit`) et les clients MCP (`build_artifact_from_kit`) ne fournissent que les paramètres : le kit apporte la mise en page et le câblage (store, clavier, horloge, collecte…). Le document produit est un document ordinaire, que l'on peut ensuite modifier par patch (`edit_preview`).

Le sommaire des kits (une ligne par kit, avec la signature de ses paramètres) est injecté dans le prompt de l'atelier et ajouté à `get_artifact_catalog` (clé `kits`).

## Kits livrés

| Kit | Rôle | Presets |
| --- | --- | --- |
| `quiz` | Quiz une question à la fois, chrono et bonus de vitesse en option, correction, score | — |
| `grid` | Jeu de grille (`sonic-matrix`) : règles en JSONata, clavier, gestes, croix directionnelle, horloge | `snake` |
| `shader` | Shader GLSL (format ShaderToy) avec jusqu'à 4 réglages reliés à `uParam0…3` | `plasma` |
| `onepage` | Page de présentation : accroche, sections, cartes, FAQ, pied de page | — |
| `survey` | Formulaire A2UI dont les réponses partent dans une collecte anonyme (`intake`) | — |

## Format d'un fichier `<id>.json`

```json
{
  "id": "quiz",
  "title": "Quiz",
  "description": "Une ligne : ce que fait le kit.",
  "use": "Quand le choisir.",
  "params": { "type": "object", "required": ["title"], "properties": { "title": { "type": "string", "maxLength": 120 } } },
  "presets": { "nom": { "title": "…" } },
  "document": { "schema": "artifacts/1", "title": { "$param": "title" } }
}
```

### `params`

`params` est un sous-ensemble de JSON Schema. Mots-clés reconnus :

- `type`, `properties`, `required`, `items`, `enum` ;
- `default` (appliqué aux valeurs absentes) ;
- `minimum` / `maximum`, `minLength` / `maxLength`, `minItems` / `maxItems` ;
- `description` (reprise dans le sommaire).

### `presets`

Le paramètre `preset` choisit un jeu de paramètres complet. Les paramètres donnés en plus le surchargent ; les objets sont fusionnés.

### Directives du squelette

Une directive est un objet dont la clé commence par `$` :

| Directive | Résultat |
| --- | --- |
| `{"$param": "a.b", "default": …}` | Valeur d'un paramètre. |
| `{"$json": …}` | Valeur développée puis encodée en chaîne JSON. À utiliser pour les attributs SDUI : `initial` d'un store, `payload`, `keymap`… |
| `{"$if": "a.b", "then": …, "else": …}` | Branche selon que le paramètre est vrai. Voir les variantes de condition ci-dessous. |
| `{"$each": "a.b", "template": …}` | Répète le gabarit pour chaque élément. Dans une liste, le résultat est mis à plat. `".x"` désigne une liste prise dans l'élément courant. |
| `{"$eachKey": "a.b", "key": …, "template": …}` | Produit un objet avec une clé par élément. |
| `{"$item": "x.y", "default": …}` | Champ de l'élément courant. `""` désigne l'élément lui-même. |
| `{"$text": "… {{a.b}} {{.x}} {{#}} {{#1}} {{^#}} …"}` | Texte composé. Placeholders listés ci-dessous. |

Variantes de condition pour `$if` :

- `"!a.b"` : la condition est inversée ;
- `"a.b=x\|y"` : vrai si la valeur vaut `x` ou `y` ;
- `".x"` : teste un champ de l'élément courant.

Sans branche correspondante, l'élément de liste ou la clé d'objet disparaît.

Placeholders de `$text` :

- `{{a.b}}` : un paramètre ;
- `{{.x}}` : un champ de l'élément courant ;
- `{{#}}` : l'index de l'élément, depuis 0 ;
- `{{#1}}` : l'index de l'élément, depuis 1 ;
- `{{^#}}` : l'index de l'élément du `$each` parent.

Tout est traité sur des valeurs décodées en objets : `{}` reste `{}`.

## Conseils

- **Neutralité.** Les kits n'imposent pas de style : couleurs du thème Concorde (`var(--sc-primary)`, `var(--sc-base-200)`, `var(--sc-success)`…), pas de dégradés ni d'aplats vifs. L'agent personnalise ensuite selon la demande.

- **Textes affichés.** Un nœud SDUI n'a pas de contenu texte. Les textes passent par un store dédié (`reducer: "$state"`) lu par `sonic-value`. Les kits `grid` et `shader` utilisent ce store « ui ».
- **Store relié à un composant.** Ne mettez pas de textes (`title`…) dans le store d'un composant relié par `dataProvider`, comme `sonic-shader` : `Subscriber` les copierait en propriétés.
- **Événements de `sonic-action`.** Il écoute `pointerdown` / `pointerup`. Pour tester, utilisez de vrais clics souris.
- **Tests.**
  - `tests/Service/ArtifactKitsTest.php` construit chaque kit avec `tests/Fixtures/kits/<id>.json` et valide le document. Ajoutez une fixture pour chaque nouveau kit.
  - Vérifiez aussi le kit dans le viewer Artefacts : jouez-le pour de vrai.
