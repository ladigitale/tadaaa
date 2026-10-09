<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Settings\AgentSettingsService;
use App\Entity\User;
use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactKits;
use Psr\Log\LoggerInterface;

final class AgentFactory
{
    public function __construct(
        private readonly AgentSettingsService $settings,
        private readonly ToolboxFactory $toolboxes,
        private readonly LoggerInterface $logger,
        private readonly ThreadStore $threads,
        private readonly ArtifactDocumentValidator $artifacts,
        private readonly ArtifactKits $kits,
    ) {
    }

    /** Runner pour un utilisateur : son modèle (ou celui du serveur), les outils du profil. */
    public function create(string $profile, User $user): AgentRunner
    {
        $index = $profile === AgentProfile::ARTIFACTS ? $this->artifacts->catalogIndex() : '';
        $kits = $profile === AgentProfile::ARTIFACTS ? $this->kits->summary() : '';

        return new AgentRunner(
            $this->settings->clientFor($user)['client'],
            $this->toolboxes->create($profile),
            $this->logger,
            systemPrompt: static fn (RunInput $input, \DateTimeImmutable $now): string => $profile === AgentProfile::ARTIFACTS
                ? SystemPrompt::artifacts($now, $input->appContext, $index, $kits)
                : SystemPrompt::build($now),
            threads: $this->threads,
            threadScope: $user->getId()->toRfc4122().'|'.$profile,
        );
    }
}
