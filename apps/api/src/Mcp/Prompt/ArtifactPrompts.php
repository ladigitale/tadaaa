<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;

/**
 * Prompts MCP : amorces des tâches courantes sur les artefacts. Chacune renvoie un message utilisateur qui nomme
 * les skills à lire (skill://…, aussi par get_artifact_skill) et l'ordre des outils. Le contenu détaillé reste
 * dans les skills (source unique) : ici seulement l'aiguillage.
 */
final class ArtifactPrompts
{
    public const ARCHETYPES = ['vitrine', 'jeu', 'outil', 'dashboard', 'experience'];

    #[McpPrompt(name: 'creer_artefact', title: 'Créer un artefact', description: 'Créer et publier un nouvel artefact Tadaaa à partir d\'une idée : skills à lire, kit ou composition libre, validation, tests, publication privée.')]
    public function creer(
        string $idee,
        #[CompletionProvider(values: self::ARCHETYPES)]
        string $archetype = '',
    ): array {
        $arch = \in_array($archetype, self::ARCHETYPES, true)
            ? "Archétype : {$archetype} (skill skill://{$archetype}/SKILL.md)."
            : 'Choisis UN archétype (vitrine, jeu, outil, dashboard, experience) d\'après la demande et lis sa skill.';

        return ['user' => <<<TXT
            Crée un artefact Tadaaa : {$idee}

            1. Lis skill://demarrer/SKILL.md puis skill://actions/creer-artefact/SKILL.md (ou get_artifact_skill si tu ne lis pas les ressources).
            2. Conception : skill://design/SKILL.md. {$arch} Ajoute multivue ou carte si besoin, et les briques des composants utilisés (skill://briques/<nom>/SKILL.md, presque toujours donnees).
            3. get_artifact_catalog(components=[…], rules=[…], examples=false) pour le détail ; search_resources avant tout shader, modèle ou son ; find_icons pour les icônes.
            4. validate_artifact jusqu'à valid=true, publish_artifact en private, puis skill tester-artefact (simulate_store, render_artifact en 390×844 et 1280×800).
            5. Corrige par update_artifact (ops / edits), puis donne l'URL et une phrase sur ce que fait la page.
            TXT];
    }

    #[McpPrompt(name: 'modifier_artefact', title: 'Modifier un artefact', description: 'Retoucher un artefact Tadaaa existant par patchs ciblés, en brouillon, puis le remettre en ligne après test.')]
    public function modifier(string $slug, string $demande): array
    {
        return ['user' => <<<TXT
            Modifie l'artefact « {$slug} » : {$demande}

            1. Lis skill://actions/modifier-artefact/SKILL.md (ou get_artifact_skill name="modifier-artefact").
            2. get_artifact(slug="{$slug}", path=…) : lis seulement la partie concernée (keysOnly sur un gros objet) ; versions=true pour l'historique.
            3. Lis la skill des briques touchées (skill://briques/<nom>/SKILL.md) avant d'écrire.
            4. update_artifact(slug="{$slug}", ops | edits, draft=true, note="…") ; jamais le document entier pour un petit changement.
            5. Teste le brouillon (skill tester-artefact), puis promote_artifact(slug="{$slug}"). En cas de régression : restore_artifact_version.
            TXT];
    }

    #[McpPrompt(name: 'tester_artefact', title: 'Tester un artefact', description: 'Vérifier un artefact Tadaaa publié ou en brouillon : réducteurs rejoués, rendu réel mobile et bureau, scénario, performances.')]
    public function tester(string $slug, string $scenario = ''): array
    {
        $s = $scenario !== '' ? "Scénario à jouer : {$scenario}" : 'Imagine le parcours principal d\'un visiteur et joue-le.';

        return ['user' => <<<TXT
            Teste l'artefact « {$slug} » et corrige ce qui casse.

            1. Lis skill://actions/tester-artefact/SKILL.md et, en cas d'erreur, skill://actions/corriger-erreurs/SKILL.md.
            2. simulate_store sur chaque store qui a un réducteur (toutes les actions, cas limites, fin de partie pour un jeu).
            3. render_artifact(slug="{$slug}") en 390×844 puis 1280×800, avec steps et assertions. {$s}
            4. Rapporte ce qui marche, ce qui casse (erreur exacte) et corrige par update_artifact draft=true si on te le demande.
            TXT];
    }

    #[McpPrompt(name: 'artefact_outil', title: 'Artefact utilisé comme outil', description: 'Donner à un artefact Tadaaa un contrat io (entrée, sortie, modes) pour l\'appeler depuis un agent ou un workflow, puis l\'essayer.')]
    public function outil(string $slug, string $entree = '', string $sortie = ''): array
    {
        return ['user' => <<<TXT
            Fais de l'artefact « {$slug} » un outil appelable (contrat io).
            Entrée attendue : {$entree}
            Sortie attendue : {$sortie}

            1. Lis skill://actions/artefact-outil/SKILL.md et skill://briques/io/SKILL.md.
            2. Écris io (schémas avec descriptions, store d'entrée, output.from / when, modes) par update_artifact draft=true.
            3. Essaie : run_artifact(document=…, input=…, dryRun=true) puis, une fois promu, run_artifact(slug="{$slug}") et get_artifact_run.
            4. Si plusieurs artefacts s'enchaînent : save_artifact_workflow puis run_artifact_workflow.
            TXT];
    }
}
