# Assets binaires d'un artefact

Un artefact peut posséder ses propres fichiers binaires : images, sons, vidéos, modèles 3D (`.glb`), JSON volumineux. Ils sont stockés en base (`artifact_assets`, `BYTEA`), servis par l'API et référencés dans le document par `@asset:<nom>`.

Ils s'ajoutent aux `files` (texte inline, 24 × 64 Ko, voir `ArtifactFiles`) sans les remplacer : `files` sert à éditer un shader ou un réducteur dans le document ; les assets servent à tout ce qui est binaire ou gros, hors du document (donc hors de sa limite de 512 Ko).

## Limites

| | |
|---|---|
| Taille par asset | 20 Mo |
| Assets par artefact | 200 |
| Types | `png jpg jpeg webp gif avif svg ogg mp3 wav flac webm mp4 glb woff2 json` |
| Nom | `a-z 0-9 . _ -`, 64 caractères max, extension obligatoire |
| Quota | compte dans le stockage du propriétaire du jeu de données (`StorageQuota`) ; les administrateurs ne sont pas limités |

Le type MIME reçu n'est jamais utilisé : il est déduit de l'extension et la **signature des octets** est vérifiée (un `.png` qui n'est pas un PNG est refusé ; un SVG contenant script, gestionnaire `on…=`, `javascript:`, `foreignObject`… est refusé).

## Envoyer un asset

- **MCP** : `upload_artifact_asset` avec `fetch {url, sha256?}` (hôtes de `ARTIFACT_FETCH_HOSTS`, GitHub raw par défaut — recommandé dès que le fichier dépasse quelques Ko) ou `contentBase64` pour un petit fichier. `list_artifact_assets` et `delete_artifact_asset` complètent.
- **HTTP** (scripts, CI), avec un jeton d'accès :

  ```bash
  curl -X PUT --data-binary @heros.png -H "Authorization: Bearer $TOKEN" \
    https://api.example.com/api/artifacts/mon-jeu/assets/heros.png
  curl https://api.example.com/api/artifacts/mon-jeu/assets -H "Authorization: Bearer $TOKEN"
  curl -X DELETE https://api.example.com/api/artifacts/mon-jeu/assets/heros.png -H "Authorization: Bearer $TOKEN"
  ```

Envoyer l'asset **avant** de publier le document qui le référence ; renvoyer un asset de même nom le remplace (l'URL change avec son empreinte).

## Référencer un asset

Toute valeur de chaîne **exactement égale** à `@asset:<nom>` est remplacée à la lecture publique par l'URL de l'asset :

```json
{"tagName": "sonic-sprite", "attributes": {"name": "heros", "shape": "image", "src": "@asset:heros.png", "w": 64, "h": 64}}
```

- Le document stocké garde la référence ; seule la lecture (`GET /api/public/artifacts/{slug}`) la remplace.
- Une référence vers un asset absent reste telle quelle.
- La validation ne vérifie que la syntaxe du nom (elle ne connaît pas l'artefact).
- L'URL est de la forme `{api}/api/public/artifacts/{slug}/assets/{nom}?v=<empreinte>` (+ `&k=<clé>` pour un artefact « lien »). `{api}` = `ARTIFACT_ASSETS_BASE_URL`, sinon l'hôte de la requête.
- Les rendus et simulations MCP (`render_artifact`, `simulate_store`) ne résolvent pas encore les assets.

## Lecture publique

`GET /api/public/artifacts/{slug}/assets/{nom}` suit exactement les droits de lecture de l'artefact (public, clé de lien `k`, membre du jeu de données). Réponse : type vérifié, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `ETag` (sha256), requêtes `Range` (lecture audio/vidéo avec déplacement). Avec `?v=<début de l'empreinte>` sur un artefact public : `Cache-Control: public, max-age=31536000, immutable`.

Un artefact **privé** n'est lisible que par un membre connecté (session) : une balise `<img>` d'un autre domaine ne peut pas s'authentifier. Pour des assets visibles du public, utiliser la visibilité « lien » ou « public ».

## Polices (`.woff2`, variables comprises)

Un `.woff2` envoyé comme asset se déclare au niveau du document :

```json
"fontFiles": [
  { "family": "Mon Titre", "src": "@asset:titre.woff2", "weight": "100 900", "style": "normal" }
]
```

`weight` accepte une valeur (`"400"`) ou une plage (`"100 900"`, police variable). 4 polices au plus. `src` est toujours une référence `@asset:` (jamais d'URL libre). Le viewer écrit les `@font-face` ; les styles utilisent ensuite `font-family: 'Mon Titre', sans-serif`. Le navigateur charge une police sans authentification : l'artefact doit être lisible par lien ou public.

**Déploiement** : la CSP du viewer (`deploy/cohost/artifacts.caddy`, directive `font-src`) doit autoriser l'hôte de l'API pour que le navigateur charge ces polices. Ce fichier n'est pas modifié par ce changement : à décider au déploiement.

## Non couvert pour l'instant

- `data.sources.x = {asset: "…"}` : lecture d'un gros JSON comme source de données (l'asset `.json` est déjà stocké et servi ; le branchement côté `data.sources` reste à faire).
- Bibliothèque d'assets partagée entre artefacts.
