---
name: design
description: Direction visuelle et checklist avant de publier toute interface visible (page, tableau de bord, formulaire, carte, catalogue). Évite le rendu générique.
---
Inspiré de Taste Skill (Leonxlnx, licence MIT), réécrit pour des artefacts SDUI : composants Concorde (sonic-*), thème et polices du document, aucun CSS libre.

## 1. Brief en 5 questions (avant d'écrire le JSON)
Réponds-y en une ligne chacune, sans les montrer à l'utilisateur sauf s'il manque une information décisive :
1. Qui lit, sur quel écran (téléphone d'abord ?) et pour quelle tâche (consulter, comparer, saisir, explorer) ?
2. Quel est l'objet principal (liste, carte, chiffres, formulaire) ? Il occupe l'écran ; le reste l'entoure.
3. Densité : aérée (lecture, marketing) | standard | dense (outil, tableau de bord).
4. Ton : sobre/institutionnel | doux/chaleureux | éditorial | brut/technique.
5. Mouvement : aucun | discret (survol, transitions) | marqué (jeu, démo). Par défaut : discret.

## 2. Directions (choisis-en UNE et tiens-la)
- Sobre : fond clair, un seul accent (type="primary"), peu de bordures, texte hiérarchisé par la taille et le poids, pas par la couleur.
- Doux : `sonic-card` (shadow "sm"), coins arrondis, espacements généreux (gap-4 à gap-6), police arrondie (fonts), couleurs douces.
- Éditorial : police à empattements pour les titres (fonts: ["Fraunces"] ou ["Newsreader"]), corps 16-18 px, lignes courtes (max 70 caractères), très peu de composants colorés.
- Brut : bordures nettes (variant="outline"), police mono (["JetBrains Mono"]), aucun ombrage (shadow="none"), accent unique saturé.
Thème : "theme" du document (light | dark | auto). Un seul thème visible à la fois ; "auto" suit le système.

## 3. Règles de composition
- Hiérarchie : un titre de page (text-2xl, font-semibold), un chapeau court (text-sm, opacity-70), puis l'objet principal. Pas plus de 3 niveaux de taille de texte.
- Espacement : échelle 2 / 4 / 6 / 8 (gap-2, gap-4…). Même gap entre éléments du même niveau. Un conteneur `grid gap-4` plutôt que des marges ad hoc.
- Mise en page : grille responsive `grid-template-columns:repeat(auto-fit,minmax(220px,1fr))` ; une colonne sur téléphone. Jamais de largeur fixe en px pour un conteneur.
- Couleur : un accent. Les statuts passent par `type` (success, warning, danger, info) des sonic-button / sonic-badge / sonic-alert, jamais par une couleur inventée. Texte secondaire : opacity-70, pas un gris codé en dur.
- Typographie : 1 ou 2 familles (fonts, 4 max). Chiffres de tableau de bord : `font-semibold` et tabulaires si possible.
- Icônes : `sonic-icon library="iconoir"`, noms vérifiés par find_icons. Une icône donne du sens (input, état, bouton), elle ne décore pas ; même taille dans un groupe.
- Boutons : une seule action primaire par zone (type="primary"), les autres en variant="outline" ou "ghost". Libellé = verbe (« Voir le détail », pas « OK »).

## 4. États à prévoir (un écran sans eux est inachevé)
Vide (`<template data-value="no-item">` avec icône et phrase d'aide), chargement (sonic-loader mode="inline"), erreur (sonic-alert status="error" avec la marche à suivre), sélection (variant du bouton ou de l'élément), désactivé, résultat filtré à zéro.

## 5. Interdits (rendu « générique »)
Dégradé violet-bleu par défaut, trois cartes identiques avec icône ronde, titre centré + sous-titre + bouton sans objet principal, emojis comme icônes, texte lorem, chiffres inventés, tout centré, ombres partout, plus de deux accents.

## 6. Avant de publier (checklist)
[ ] Brief fait, une seule direction. [ ] L'objet principal est visible sans défiler sur téléphone. [ ] Un titre, une action primaire par zone. [ ] Tous les états (vide, chargement, erreur) existent. [ ] Chaque input a un label et, si utile, une icône. [ ] Aucune donnée inventée : source ou « exemple ». [ ] validate_artifact sans erreur, puis render_artifact (captures téléphone 390 px et bureau 1100 px) regardées vraiment.

## 7. Audit d'un artefact existant
Capture (render_artifact), liste les écarts à la checklist, corrige du plus visible au moins visible (hiérarchie, espacements, états, accent), republie avec update_artifact (ops/edits plutôt que tout le document).
