<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\AgUi\EventSink;
use App\Service\JsonShape;

/** Ce qu'un outil voit pendant un run : émettre des événements AG-UI, l'état de l'atelier. */
final class ToolContext
{
    private int $counter = 0;

    /**
     * Arguments de l'appel d'outil en cours, décodés en objets (`{}` distinct de `[]`),
     * quand le client LLM les fournit. Voir {@see JsonShape}.
     */
    public ?\stdClass $rawInput = null;

    /**
     * État des outils conservé d'un run à l'autre de la conversation ({@see \App\Agent\ThreadStore}) :
     * dernier aperçu valide, artefact publié…
     *
     * @var array<string, mixed>
     */
    public array $workspace = [];

    /**
     * @param array{artifactSlug?: string} $appContext contexte fourni par l'application (RunInput)
     */
    public function __construct(
        public readonly EventSink $sink,
        public readonly string $runId,
        public readonly array $appContext = [],
    ) {
    }

    /**
     * Rétablit les objets vides (`{}`) de l'argument `$argument` dans `$value` (sa version
     * décodée en tableaux), pour le réémettre tel que le modèle l'a écrit.
     */
    public function withEmptyObjects(mixed $value, string $argument): mixed
    {
        $raw = $this->rawInput !== null && property_exists($this->rawInput, $argument) ? $this->rawInput->{$argument} : null;

        return $raw === null ? $value : JsonShape::restore($value, JsonShape::emptyObjectPaths($raw));
    }

    /** Identifiant court et unique dans le run (surfaces, messages). */
    public function nextId(string $prefix): string
    {
        return sprintf('%s-%s-%d', $prefix, substr(preg_replace('/[^A-Za-z0-9]/', '', $this->runId) ?: 'run', 0, 8), ++$this->counter);
    }
}
