---
name: vitrine
description: Site vitrine, landing, one-page qui défile, page d'événement, portfolio, CV, récit éditorial, diaporama. Structure de sections, héros, typographie expressive, révélations au défilement, fonds shader ou procéduraux.
---
Prérequis : rules=["design"]. Une vitrine se juge en 5 secondes sur téléphone : qui, quoi, pourquoi maintenant, que faire.

## 1. Squelette
- `page: {"padding":"none","background":"<fond>"}` puis un conteneur racine qui porte la palette (design §1). Une seule vue (pas d'onglets du viewer) ; d'autres vues seulement pour des pages vraiment séparées (mentions, programme détaillé), cachées (`hidden:true`) et atteintes par `sonic-button href="#programme"`.
- Section = `section` pleine largeur, `padding:clamp(48px,8vw,112px) clamp(20px,6vw,72px)`, contenu interne `max-width:72rem;margin:0 auto`. Alterner deux fonds proches (`--sc-base` / `--sc-base-100`) ou une section sombre pour rythmer.
- Ordre type : héros → preuve (chiffres, logos, citation) → offre/contenu en 2–4 blocs → détail (programme, tarifs, FAQ) → appel final → pied de page. Supprime ce qui n'a pas de contenu réel.
- Navigation : pas d'ancres internes (elles ne défilent pas). En-tête `position:sticky;top:0;z-index:10;backdrop-filter:blur(10px);background:color-mix(in oklab,var(--sc-base) 80%,transparent)` avec le nom et UNE action (bouton vers l'action clé ou une vue cachée). Si le contenu mérite des pages, utilise des vues.
- Kit `onepage` (start_from_kit / build_artifact_from_kit) = base honnête ; réécris ensuite le héros et la typographie, sinon le rendu reste générique.

## 2. Héros (l'objet principal de la page)
Choisis UN modèle :
- **Typographique** : titre énorme `font-size:clamp(44px,9vw,128px);line-height:.92;letter-spacing:-.035em;max-width:12ch;text-wrap:balance`, sur-titre en petites capitales, une phrase, 1 bouton primaire + 1 ghost. Aligné à gauche, ancré en bas (`min-height:min(92svh,820px);display:grid;align-items:end`).
- **Fond vivant** : `sonic-shader` en `position:absolute;inset:0;z-index:-1;width:100%;height:100%` (le parent : `position:relative;isolation:isolate;overflow:hidden`) + voile `linear-gradient(to top,<fond> 5%,transparent 60%)` pour la lisibilité. Shader lent (`iTime*.1–.2`), 2–3 couleurs de la palette, contraste bas derrière le texte.
- **Scène illustrée** : `sonic-procgen` (scènes : port, rue vivante, plaine des montgolfières, forêt de nuit, ville néon, fond marin, désert, espace profond…) avec `style="display:block;aspect-ratio:1100/680;width:100%"` et `style` du DP (`night`, `palette.hue`, `grain`, `vignette`) accordé à la palette ; texte à côté (grille 2 colonnes `repeat(auto-fit,minmax(20rem,1fr))`) ou dessous, jamais dessus sans voile.
- **Objet 3D** : `sonic-3d` avec GLB en asset (`@asset:objet.glb`), `auto-rotate`, `fit`, petites `min/max-polar` ; pour produit, maquette, pièce.
- **Image** : `sonic-image` (asset uploadé ou URL https), `ratio`, `cover`, `rounded="xl"` ; légende en `figcaption`.

## 3. Sections qui ne font pas « template »
- **Chiffres-preuves** : 3–4 grands nombres `sonic-animate recipe="count-up" from="0" to="1200" text-suffix=" spectateurs"` (enfant = `p` avec le texte final), étiquette dessous. Vrais chiffres uniquement.
- **Offre** : grille asymétrique (un bloc 2× plus large) plutôt que 3 cartes égales ; ou liste numérotée éditoriale (`01`, `02` en mono, filets `border-top:1px solid color-mix(in oklab,var(--sc-base-content) 15%,transparent)`).
- **Programme / horaires** : `sonic-list` + gabarit (heure en tabulaire à gauche, titre, lieu) ; filtrage par jour = store + `sonic-jsonata` (voir rules.listes).
- **Tarifs** : 2–3 colonnes, une mise en avant par `sonic-card type="primary"` ou bordure accent, prix en très gros, liste d'inclus avec `sonic-icon name="check"`.
- **Témoignages** : vrai texte en `blockquote` serif grand corps, nom en petites capitales ; jamais de citation inventée.
- **FAQ** : questions en `h3` + réponses courtes en deux colonnes (`grid-template-columns:repeat(auto-fit,minmax(18rem,1fr))`) ; 5–8 max.
- **Galerie** : grille `grid-template-columns:repeat(auto-fill,minmax(12rem,1fr));grid-auto-flow:dense` ; une image sur deux en `grid-column:span 2` pour casser la monotonie.
- **Carte** d'accès : rules=["carte"], hauteur `min(60vh,520px)`.
- **Formulaire** (contact, inscription) : collecte (rules.collecte / kit `survey`), 3–5 champs, bouton verbe (« Recevoir le programme »).
- **Appel final** : grande phrase + un bouton ; fond accent (`background:var(--sc-primary);color:var(--sc-primary-content)`).

## 4. Mouvement au défilement
- `sonic-animate recipe="reveal"` (ou `slide-up`) `trigger="viewport"` `stagger="0.08"` sur les groupes d'enfants d'une section ; `once="true"`. Une section sur deux au plus, jamais le texte courant.
- `typewriter` réservé à un seul titre. `count-up` pour les chiffres.
- Un « moment signature » unique : timeline (`sonic-clock` + `sonic-timeline`) au chargement du héros, ou shader qui réagit à la souris (`mouse`).
- Pas de lien scroll → animation disponible (pas de DP de défilement) : pour un récit « scrollytelling », passe en diaporama (§5) ou en scrub par curseur (rules.animation).

## 5. Récit, diaporama, présentation
- Une vue par chapitre (`views[]`, `hidden:true` sauf la première si tu ne veux pas d'onglets) ; boutons « Suivant / Précédent » = `sonic-button href="#chapitre-2"`.
- Diaporama dans une seule vue : store `deck` `{i:0,n:8}` + `sonic-keyboard scope="document"` (`ArrowRight`→`next`, `ArrowLeft`→`prev`) + `sonic-gesture` (swipe) + `sonic-if dataProvider="deck" key="i" equals="3"` par diapositive ; `sonic-wake-lock auto` et `sonic-fullscreen` (bouton `for`) pour présenter ; numéro `sonic-value format="00"`. Diapositive : un seul message, titre ≥ 48 px, 16/9 via `aspect-ratio:16/9;width:min(100vw,177svh)`.

## 6. Directions prêtes à l'emploi (palette de zone + polices)
- **Éditorial papier** : fond #f3efe6, texte #1d1a16, accent #c2410c ; Fraunces 900 (titres) + Inter ; filets fins, beaucoup de blanc, images N&B.
- **Nuit festival** : fond #0b0b12, texte #f4f1ea, accent #ff6a2b ; Fraunces ou Anton + Inter ; héros shader chaud.
- **Suisse/institutionnel** : fond #ffffff, texte #111, accent #e30613 ; Inter Tight 800 serré ; grille 12 colonnes stricte, aucune ombre.
- **Doux/associatif** : fond #fff8f1, texte #2a2320, accent #2f7d6d ; Fredoka + Nunito ; rayons 20–28 px, ombres larges et pâles.
- **Brut/tech** : fond #f2f2f2, texte #0a0a0a, accent #0047ff ; Space Grotesk + JetBrains Mono ; bordures 2 px noires, zéro arrondi.

## 7. Contrôle spécifique
Captures 390 px et 1280 px : le titre tient sans césure laide, le bouton primaire est visible sans défiler sur téléphone, le contraste texte/fond vivant reste lisible sur toute la hauteur, chaque section a une raison d'exister.
