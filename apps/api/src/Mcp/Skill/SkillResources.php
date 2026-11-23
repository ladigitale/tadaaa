<?php

declare(strict_types=1);

namespace App\Mcp\Skill;

use Mcp\Capability\Attribute\McpResource;

/**
 * Compétences de conception des artefacts servies comme Agent Skills sur MCP (convention SEP-2640, socle
 * « ressources ») : une ressource `skill://<nom>/SKILL.md` par compétence, texte du fichier tel quel (en-tête YAML
 * name / description compris). Nom et description de chaque ressource = ceux de l'en-tête (vérifié par
 * SkillResourcesTest). Les mêmes fichiers arrivent aussi par get_artifact_catalog rules=[…].
 */
final class SkillResources
{
    /** Noms servis, dans l'ordre des méthodes. */
    public const NAMES = ['carte', 'dashboard', 'design', 'experience', 'jeu', 'multivue', 'outil', 'vitrine'];

    private const DIR = __DIR__.'/../../../config/artifacts/skills';

    #[McpResource(uri: 'skill://carte/SKILL.md', name: 'carte', description: 'Carte interactive pilotée par DataProviders (sonic-map) : points cliquables, vue publiée, fond de carte, état de la carte. À lire avant d\'écrire une carte.', mimeType: 'text/markdown')]
    public function carte(): string
    {
        return $this->read('carte');
    }

    #[McpResource(uri: 'skill://dashboard/SKILL.md', name: 'dashboard', description: 'Tableaux de bord, KPI, rapports chiffrés, suivi en direct : hiérarchie des chiffres, grille de panneaux, graphiques sonic-plot (line, area, bar, barh, scatter, pie, donut, histogram, heatmap, sparkline) pilotés par DataProvider, filtres en radios, survol et clic publiés, formats de nombres, données collectées.', mimeType: 'text/markdown')]
    public function dashboard(): string
    {
        return $this->read('dashboard');
    }

    #[McpResource(uri: 'skill://design/SKILL.md', name: 'design', description: 'À lire en premier avant toute interface visible. Ce qui marche vraiment pour styler un artefact (style inline, jetons, palettes de zone), le brief, le choix de l\'archétype (vitrine, jeu, outil, dashboard, expérience), la checklist et le contrôle visuel.', mimeType: 'text/markdown')]
    public function design(): string
    {
        return $this->read('design');
    }

    #[McpResource(uri: 'skill://experience/SKILL.md', name: 'experience', description: 'Expériences créatives et immersives (art génératif, visuels audio-réactifs, caméra et effets, IA locale, instruments et séquenceurs, enregistreurs, installations et bornes, scrub/timeline) : une scène plein cadre, des commandes discrètes, le bon composant de la creative-stack pour chaque idée.', mimeType: 'text/markdown')]
    public function experience(): string
    {
        return $this->read('experience');
    }

    #[McpResource(uri: 'skill://jeu/SKILL.md', name: 'jeu', description: 'Jeux vidéo plein écran (arcade, plateau, puzzle, physique, 2.5D, 3D, multijoueur) : choix du moteur de rendu, machine à états, écrans titre/pause/fin, HUD, commandes clavier/tactile/manette, son, « juice », tests par simulate_store et render_artifact.', mimeType: 'text/markdown')]
    public function jeu(): string
    {
        return $this->read('jeu');
    }

    #[McpResource(uri: 'skill://multivue/SKILL.md', name: 'multivue', description: 'Interfaces à plusieurs panneaux ou vues (onglets, liste + détail, modal, carte + liste) avec un état partagé. À lire avant d\'écrire des onglets, un modal ou une sélection.', mimeType: 'text/markdown')]
    public function multivue(): string
    {
        return $this->read('multivue');
    }

    #[McpResource(uri: 'skill://outil/SKILL.md', name: 'outil', description: 'Interfaces expertes et multi-pages (back-office, catalogue filtrable, recherche, CRUD, configurateur, formulaire long, gestion de stock ou de planning) : densité, barre d\'outils, filtres, liste/détail, tables, raccourcis, formulaires. À combiner avec multivue.', mimeType: 'text/markdown')]
    public function outil(): string
    {
        return $this->read('outil');
    }

    #[McpResource(uri: 'skill://vitrine/SKILL.md', name: 'vitrine', description: 'Site vitrine, landing, one-page qui défile, page d\'événement, portfolio, CV, récit éditorial, diaporama. Structure de sections, héros, typographie expressive, révélations au défilement, fonds shader ou procéduraux.', mimeType: 'text/markdown')]
    public function vitrine(): string
    {
        return $this->read('vitrine');
    }

    private function read(string $name): string
    {
        $path = self::DIR.'/'.$name.'.md';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
