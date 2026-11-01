# Chantiers agents, SDUI et composants graphiques

Novembre 2026. Constats tirés du code de `tadaaa`, `artifacts` et `creative-stack`, du chat « Jeu de foot au tour par tour » (artefact `foot-au-coup-par-coup`) et du chantier « carte BITD » (`sonic-map`). Chaque point indique s'il est **corrigé** dans les branches `feat/sdui-templates-bindings` (tadaaa, artifacts) et `feat/map-addon` (creative-stack), ou **à faire**.

## 1. SDUI d'abord, A2UI pour les interfaces d'agent

**Constat.** Le serveur orientait lui-même l'agent vers A2UI : règle « (3) une liste de longueur variable se fait en vue A2UI, pas en SDUI » (`ArtifactDocumentValidator`) et invite de l'atelier « pour une liste ou un formulaire, préfère A2UI » (`SystemPrompt`). Côté SDUI, `<template>` n'était pas une balise autorisée : `sonic-list` était inutilisable. Résultat dans le chantier BITD : 10 lignes fixes et un lien statique par entreprise (340 Ko).

**Vérifié.** Concorde gère déjà nativement `template` dans le SDUI et les gabarits de `sonic-list` ; dans une ligne, `sonic-value key`, `data-bind ::text-content`, `::href` et `::payload` (clic de ligne vers un store) fonctionnent.

**Corrigé.**
- `template` ajouté aux balises sûres (catalogue et générateur `build-artifact-catalog.mjs`).
- Nouvelle règle longue `listes` (gabarit, liaisons permises, clic de ligne, dérivation d'un store par `sonic-jsonata`) ; règle (3) et invite de l'atelier réécrites : SDUI + Concorde par défaut, A2UI pour les interfaces qui dialoguent avec un agent.
- Viewer : `data-bind` était **inerte** dans les artefacts. Concorde n'observe les liaisons que dans le document et dans le shadow root de ses propres composants, or l'artefact est rendu dans celui de `artifact-viewer`. Le viewer l'observe désormais (`artifact-viewer.ts`, `createRenderRoot`).

**Pièges relevés (documentés dans la règle).** Un `dataProvider` pointé (`etat.items`) ne fonctionne pas pour `sonic-list` ; `key` ne sert qu'avec `fetch` ; une `data.transforms` ne lit que les `data.sources`, pas les stores : pour dériver d'un store, `sonic-jsonata` (`inputProvider` → `outputProvider`, `formDataProvider` pour les filtres).

**À faire.** Côté Concorde (à votre main) : que `sonic-sdui` observe lui-même sa racine de rendu, pour que `data-bind` marche partout où il est intégré.

## 2. Sécurité des liaisons `data-bind` (corrigé, à confirmer en amont)

**Constat, reproduit.**
- Le validateur ne filtrait que les attributs `on*` et `srcdoc` : `"::inner-html": "$q"` était accepté (confirmé avec `validate_artifact` en production). Concorde écrit alors la donnée en `innerHTML` : un `onerror` s'exécute.
- `data-bind` évalue `Function("return …")` quand l'expression commence par `|` (format `js`). `"::text-content": "|$q"` exécute du JavaScript avec la donnée injectée dedans.
- Exposition : dans le viewer, la CSP (`script-src` sans `unsafe-inline` ni `unsafe-eval`) bloque l'exécution, mais pas l'injection de HTML (liens, faux formulaires). En **intégration** (`embed.js`), l'artefact tourne dans la page hôte : c'est la CSP du site hôte qui décide. Les données peuvent venir de visiteurs (`data.sources` avec `intake`).

**Corrigé.**
- Validateur (`validateBinding`) : liaisons `::…-html`, `::on…`, `::style`, `::srcdoc`, `::action`, `::formaction` refusées ; formats `|…` et `js|…` refusés ; une URL liée (`::href`, `::src`…) doit commencer par une URL `https` littérale ; 5 tests.
- Viewer : même garde dans `sdui-guard.ts` (document refusé avec message), et `Format.js` neutralisé au démarrage (`concorde-sdui-runtime.ts`) pour les anciens documents et l'intégration.

**À faire (Concorde, votre décision).** Supprimer ou désactiver par défaut le format `js` de `data-bind`, et refuser `::*-html` sur des données non fiables.

## 3. Composants graphiques : DataProviders, gestes, documentation

| Composant | Ce qui manque | Vu dans |
|---|---|---|
| `sonic-gesture` | Un seul pointeur (`if (this.pointerId != null) return`) : **pas de pincement ni de multipoint**. Le glisser publie le déplacement **cumulé depuis le début, en fraction de la zone** (pas en pixels, pas de delta) ; la fin d'un glisser déclenche aussi un `swipe-*` ou un `tap`. Pas de position dans `tap`, pas de molette, pas d'état publié en DataProvider. `double-tap-ms` et `dispatch-event` absents du manifeste. Doc : une ligne. | foot (zoom au doigt impossible, glisser câblé sur des pixels supposés) |
| `sonic-2d` | La caméra se pilote par DP, mais `out-data-provider` ne publie que le statut : ni caméra, ni pointeur en coordonnées du monde. Pas de pan / pincement intégrés. | foot (caméra pilotée à la main dans le réducteur) |
| `sonic-shader` | 4 `uParam` seulement ; un canal (`channel0`…) n'accepte qu'une URL, un `#id` ou une référence média : **aucun moyen de passer un tableau de données d'un store comme texture** (`channelFormValue` renvoie `undefined`). | foot (clé `channel0` devinée par analogie, état encodé en texture non garanti) |
| `sonic-3d` | Orbite à la souris et au doigt (OrbitControls) ; caméra en entrée. À vérifier : publication continue de la caméra. | — |
| Tous | La doc des addons (`src/addons/*/*.md`, 2 276 lignes) **n'arrive jamais à l'agent** : le catalogue ne transmet que les descriptions, tronquées à 240 caractères. | foot, BITD |

**Proposé (à faire).**
- `sonic-gesture` multipoint : suivre tous les pointeurs ; émettre `pinch {scale, dScale, cx, cy}`, `pan {dx, dy}` en delta pixels, `drag-end`, `tap {x, y}` en coordonnées de la zone, `wheel {dy}` ; option `state-provider` qui publie `{pointers, scale, dx, dy}` ; ne plus émettre de `swipe` après un glisser.
- `sonic-2d` : publier `camera` et `pointer {x, y}` monde dans `out-data-provider` ; option `controls="pan pinch wheel"` intégrée.
- `sonic-shader` : canal de données `data:{width, height, format, values}` (ou clé de DP → `DataTexture`), et `uParam` en tableau.
- Catalogue : exposer la doc d'un addon à la demande (`get_artifact_catalog(docs=["2d"])`, contenu des `.md` du paquet creative-stack livré).

**Fait.** `sonic-map` (addon `map`) applique ces principes : vue entièrement pilotée par DP, état complet publié (`state`), style MapLibre complet par DP, pincement et rotation tactiles natifs, doc `map.md`.

## 4. Agentisme

**Corrigé.**
- **`render_artifact` ne marchait jamais** (« Impossible de joindre https://api.tadaaa.space ») : le viewer construit appelle son API en URL absolue, et la garde anti-SSRF de l'outil bloque tout ce qui n'est pas local ; le serveur local de prévisualisation n'était donc jamais appelé. L'outil sert maintenant ces chemins localement quel que soit l'hôte (`render-artifact.mjs`, les deux copies) et laisse passer les seules URL CDN des `scripts` déclarés par le document (résolues par `ArtifactRenderer` via le catalogue). Vérifié contre un build de prod du viewer : liste, liaisons, clic, refus des liaisons dangereuses, exemple BITD.
- **Réponses MCP deux fois trop lourdes** : `TextContent` encodait les tableaux en JSON indenté avec accents et `/` échappés. Le catalogue compact passait de 96 Ko à 213 Ko. Les outils renvoient désormais du JSON compact non échappé (`ArtifactMcpProcessor`).
- Concorde 5.1.3 dans le viewer et le catalogue ; MapLibre 5.24 (globe) dans le catalogue de scripts.

**À faire.**
- Le catalogue reste lourd : 96 Ko sans filtre, 53 Ko même sans règles ni exemples (141 composants). Proposer l'index (`catalogIndex`, 9 Ko) par défaut et exiger `components` pour le détail.
- Limites découvertes en cours de route : réducteur 32 Ko, transform 4 Ko, 8 stores, 200 nœuds par publication, 24 fichiers de 64 Ko, document 512 Ko. Les regrouper dans une règle courte `limites` et les renvoyer dans les erreurs de validation avec la marge restante.
- `manifest.scripts` des addons n'est ni appliqué ni vérifié (un document doit penser à déclarer `"scripts"`).
- Ressources agent de Concorde : le skill `concorde` renvoie vers `/crawl/…html`, qui répond en erreur ; les pages sans `/crawl/` fonctionnent. Aligner le skill, `llms.txt` et le site.
- **Is Agentic** (is-agentic.com, Vercel / Ora) : 80 points sur les essentiels (contenu rendu côté serveur, HTTP, structure, métadonnées), 20 sur API / OAuth / MCP détectés, 5 de bonus. `artifacts.tadaaa.space` rend tout côté client : un agent n'y voit pas de contenu. Piste : page publique pré-rendue ou version Markdown (`Accept: text/markdown`) depuis `/api/public/artifacts/{slug}`, avec titre, description, langue, Open Graph ; déclarer `is-agentic-site-type`. Lancer le scan sur `concorde.supersoniks.org` et `artifacts.tadaaa.space` pour avoir le détail des critères (non publié).

## 5. Carte : `sonic-map`

MapLibre plutôt que Leaflet : style vectoriel déclaratif (pilotable par DP), inclinaison, rotation, relief, globe (v5), clusters, gestes tactiles. Leaflet reste plus rapide pour quelques milliers de points mais n'a ni vecteur natif ni 3D, et sa v2 n'est pas stabilisée.

Addon `creative-stack/src/addons/map` : composant, cœur pur testé (8 tests), doc `map.md`, exemple `examples/bitd-gicat.sdui.json` (495 entreprises du GICAT, données en dur dans `examples/data/bitd-gicat.json`, positions au centre de la commune, 34 sans position). Rendu vérifié dans le viewer construit : 495 entreprises, 461 sur la carte, recherche « 37 » → 2 résultats, clic de ligne → fiche, lien Google Maps en lat/lon.

Non vérifié : le fond de carte (OpenFreeMap, `tiles.openfreemap.org`) dans le vrai viewer en ligne ; l'outil de rendu bloque le réseau externe. La CSP du viewer l'autorise (`connect-src https:`, `img-src https:`, `worker-src blob:`). Sans fond, la carte retombe sur un fond uni et garde ses points.
