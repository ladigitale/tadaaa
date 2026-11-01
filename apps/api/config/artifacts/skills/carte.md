---
name: carte
description: Carte interactive pilotée par DataProviders (sonic-map) : points cliquables, vue publiée, fond de carte, état de la carte. À lire avant d'écrire une carte.
---
`sonic-map` (addon map, MapLibre GL) se pilote entièrement par DataProviders ; aucun code.
- `points-provider="pts"` : DP `{ "data": [ {"id", "lat", "lon", "label"} ] }`. Ignore les points sans coordonnées (`$f[lat != null]`) ; sans cela ils tombent en (0,0).
- `view-provider="vue"` : `{center:[lon,lat], zoom, mode:"fly"|"ease"|"jump", duration, seq, selectedId}` ou `{fit:"points", …}` pour cadrer tous les points. Incrémente `seq` pour rejouer le même cadrage.
- `style-provider="fond"` : `{style: "https://tiles.openfreemap.org/styles/positron"}` ; `allowed-hosts` doit lister l'hôte des tuiles (par exemple `tiles.openfreemap.org`). Pas d'URL libre.
- `state-provider="carteEtat"` : publie la vue courante (centre, zoom) ; `target="etat"` + `pick-action-type="select"` envoie le clic sur un point comme action ; `auto-select` met en évidence le point choisi.
- Hauteur obligatoire : `style="display:block;height:min(70vh,640px)"`.
- Beaucoup de points : l'addon agrège en clusters ; garde `label` court.
- Liens externes depuis une fiche : `a` avec `data-bind "::href":"https://…$champ"` (https littéral en tête) ; `target="_blank" rel="noopener noreferrer"`.
- Écran avec carte + liste : `sonic-if` par onglet ; le composant carte est recréé à chaque retour sur l'onglet, la vue publiée le recadre.
Voir aussi rules.multivue (onglets, modal) et rules.listes.
