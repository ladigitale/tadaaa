<?php

declare(strict_types=1);

namespace App\Agent\Llm;

/**
 * Ce que le client LLM signale pendant la génération, quand il la reçoit en flux :
 * le texte au fil de l'eau, et le début d'un appel d'outil (avant ses arguments, qui
 * peuvent être longs : un document d'artefact entier).
 */
final class LlmStream
{
    /**
     * @param (\Closure(string): void)|null         $onText      morceau de texte
     * @param (\Closure(string, string): void)|null $onToolStart id, nom de l'outil
     * @param (\Closure(): void)|null               $onWait      appelé régulièrement tant que le fournisseur ne
     *                                                            renvoie rien (génération longue, sans flux) :
     *                                                            garde la connexion et le client en éveil
     */
    public function __construct(
        private readonly ?\Closure $onText = null,
        private readonly ?\Closure $onToolStart = null,
        private readonly ?\Closure $onWait = null,
    ) {
    }

    public function waiting(): void
    {
        if ($this->onWait !== null) {
            ($this->onWait)();
        }
    }

    public function text(string $delta): void
    {
        if ($this->onText !== null && $delta !== '') {
            ($this->onText)($delta);
        }
    }

    public function toolStart(string $id, string $name): void
    {
        if ($this->onToolStart !== null) {
            ($this->onToolStart)($id, $name);
        }
    }
}
