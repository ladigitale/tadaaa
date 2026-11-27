# Skills des artefacts (MCP)

Les agents qui écrivent des artefacts sont guidés par des Agent Skills servies par le MCP Tadaaa selon l'extension
`io.modelcontextprotocol/skills` (SEP-2640).

## Ce que voit un client MCP

| Mécanisme | Où |
|---|---|
| `instructions` du serveur (le pilote : où commencer, règles d'or) | `apps/api/config/packages/mcp.yaml` |
| extension déclarée dans `capabilities.extensions["io.modelcontextprotocol/skills"]` | `SkillsExtension`, passe de compilation dans `Kernel` |
| `skills/list`, `skills/get` (manifeste complet, empreintes sha256), `resources/directory/read` (`directoryRead: true`) | `SkillsRequestListener` (le SDK 0.6 refuse les méthodes inconnues : servies avant lui, après le pare-feu) |
| ressources `skill://<chemin>/SKILL.md` et fichiers annexes | `SkillLoader` (`resources/list`, `resources/read`) |
| outil `get_artifact_skill` (clients sans ressources) | `GetArtifactSkillTool` |
| `get_artifact_catalog rules=[…]` (skills en fichiers) | `ArtifactDocumentValidator::fileSkills()` |
| prompts `creer_artefact`, `modifier_artefact`, `tester_artefact`, `artefact_outil` | `src/Mcp/Prompt/ArtifactPrompts.php` |

Source unique : `App\Service\ArtifactSkills`.

## Familles

- **pilote** : `skill://demarrer/SKILL.md` (+ `references/outils.md`, carte des outils par tâche) ;
- **conception** (archétypes, aussi dans le sommaire de l'atelier) : `skill://design`, `vitrine`, `jeu`, `outil`, `dashboard`, `experience`, `multivue`, `carte` ;
- **actions** (ce que fait l'agent) : `skill://actions/<nom>` : `creer-artefact`, `modifier-artefact`, `tester-artefact`, `publier-artefact`, `fichiers-assets`, `ressources`, `donnees-collecte`, `artefact-outil`, `direct-flux`, `corriger-erreurs` ;
- **briques** (familles de composants) : `skill://briques/<nom>`, générées à partir de `config/artifacts/skills/briques.json` : règles du catalogue (`rules`) + description de chaque composant des addons (`addons`, `components`). Toujours à jour du catalogue, sans texte à recopier.

## Ajouter ou modifier une skill

1. Dossier `config/artifacts/skills/<chemin>/` avec `SKILL.md` ; le dernier segment du chemin = `name`.
2. Frontmatter YAML **strict** : citer la description dès qu'elle contient « : » (sinon un lecteur de skills la rejette) ; `name` en `a-z0-9-` (64 max) ; `description` qui dit QUAND lire la skill (1024 max) ; `metadata.famille`.
3. Fichiers annexes dans le même dossier (`references/…`) : servis et listés dans le manifeste.
4. Une brique : une entrée dans `briques.json` (nom, description, règles, addons ou composants, skills liées).
5. `tests/Mcp/ArtifactSkillsTest.php` vérifie format, noms, manifeste, renvois `skill://…` du pilote.
