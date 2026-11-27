---
name: demarrer
description: "Point d'entrée pour tout travail sur les artefacts Tadaaa (pages interactives SDUI publiées par le MCP Tadaaa) : quelle skill lire selon la tâche (créer, modifier, tester, publier, données, outil io, direct), l'ordre des appels d'outils et les pièges qui coûtent le plus cher. À lire en premier, avant tout appel à publish_artifact ou update_artifact."
metadata:
  famille: pilote
---
# Démarrer avec les artefacts Tadaaa

Un artefact est un document JSON `artifacts/1` (vues SDUI de composants Concorde `sonic-*` et de la creative-stack, état en DataProviders et réducteurs JSONata, aucun JavaScript) publié par Tadaaa et affiché par le viewer Artefacts. Tu l'écris, le valides, le testes dans un vrai navigateur et le publies avec les outils de ce serveur.

## 1. Choisir la skill d'action (ce que tu fais)

| Tâche | Skill |
|---|---|
| Nouvel artefact (idée, demande, kit) | `creer-artefact` |
| Retoucher un artefact existant, gros document, retour arrière | `modifier-artefact` |
| Vérifier avant de livrer (erreurs, rendu, jeu jusqu'au bout) | `tester-artefact` |
| Brouillon, mise en ligne, visibilité, lien, icône, image de partage | `publier-artefact` |
| Images, sons, modèles 3D, polices, gros fichiers | `fichiers-assets` |
| Shader, modèle, son ou jeu de données réutilisable (bibliothèque) | `ressources` |
| Formulaire, vote, scores, lecture et écriture des données collectées | `donnees-collecte` |
| Artefact appelé comme outil (contrat io, run_artifact, workflows) | `artefact-outil` |
| Direct, régie, télécommande, spectateurs, appairage | `direct-flux` |
| La validation refuse le document ou le rendu casse | `corriger-erreurs` |

## 2. Choisir les skills de conception (ce que tu construis)

Toute interface visible : `design` d'abord (style inline, jetons, palettes de zone, checklist), puis UN archétype : `vitrine` (site, landing, récit), `jeu` (plein écran), `outil` (interface experte, filtres, formulaires), `dashboard` (KPI, graphiques), `experience` (art génératif, audio, caméra, IA). En plus selon le besoin : `multivue` (onglets, liste + détail, modal), `carte` (sonic-map).

## 3. Choisir les briques (les composants que tu utilises)

Une skill `briques/<nom>` par famille de composants, avec la règle du catalogue et la description de chaque composant : `donnees` (presque toujours), `listes`, `navigation`, `icones-polices`, `a2ui`, `shader`, `son`, `audio`, `media`, `vision`, `ia`, `physique`, `3d`, `2d`, `procgen`, `monde`, `monde2d`, `mobilier`, `animation`, `appareil`, `bluetooth`, `laser`, `salons`, `collecte`, `lieux`, `io`, `partage`, `dessin`, `graphiques`, `essaim`, `markdown`.

Sans lecteur de skills : `get_artifact_skill(name)` renvoie la même chose ; `get_artifact_catalog(rules=[…])` renvoie les règles et les skills de conception.

## 4. L'ordre qui marche

1. Lire les skills utiles (ci-dessus). Ne pas écrire de mémoire : composants, props et icônes inventés sont refusés.
2. `get_artifact_catalog(components=[ceux que tu utilises], rules=[thèmes], examples=false)` : seulement le détail utile.
3. `search_resources` avant de générer un shader, un modèle, un son ou des données.
4. Écrire le document ; `validate_artifact` jusqu'à `valid: true` (corriger toutes les erreurs en une passe).
5. Publier en `private` (`publish_artifact`) ou en brouillon (`update_artifact` + `draft: true`) puis tester : `simulate_store` (réducteurs), `render_artifact` (vrai viewer, mobile 390×844 et bureau 1280×800).
6. Corriger par `update_artifact` avec `ops` / `edits` (jamais le document entier pour un petit changement), puis `promote_artifact` ou changer la visibilité.
7. Donner l'URL renvoyée.

## 5. Pièges qui coûtent cher

- Un nom de `dataProvider` / `target` mal écrit ne fait pas d'erreur de validation : composant vide. Relire chaque nom contre `data.stores`, `data.transforms`, `data.sources`.
- `"attributes": {}` est enregistré comme `[]` puis refusé au rendu : toujours au moins un attribut (`{"style": ""}`). Même chose pour un objet vide dans `io.stream.actions.<nom>` ou un schéma : donner une `description`.
- 8 `sonic-store` au plus par document ; un store de `data.stores` devient un élément dont l'id est son nom : ne pas donner le même nom à un composant.
- Pas de bouton d'interface en `sonic-action trigger="pointerdown"` (empêche de défiler) ; les choix passent par des radios Concorde (`formDataProvider`, `name`, `value`, `radio`).
- Listes de choix dans un `sonic-pop` ; formulaires en `sonic-fieldset` + `sonic-form-layout` ; alertes en toast (`sonic-notify`) ou modale, jamais en texte gris permanent.
- Caméra, micro, Bluetooth, port série… : déclarer `capabilities` à la racine, démarrer sur un clic.

Carte complète des outils par tâche : `references/outils.md`.
