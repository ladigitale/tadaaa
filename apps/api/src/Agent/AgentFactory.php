<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Settings\AgentSettingsService;
use App\Entity\User;
use Psr\Log\LoggerInterface;

final class AgentFactory
{
    public function __construct(
        private readonly AgentSettingsService $settings,
        private readonly ToolboxFactory $toolboxes,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Runner pour un utilisateur : son modèle (ou celui du serveur), les outils du profil. */
    public function create(string $profile, User $user): AgentRunner
    {
        return new AgentRunner(
            $this->settings->clientFor($user)['client'],
            $this->toolboxes->create($profile),
            $this->logger,
            systemPrompt: static fn (RunInput $input, \DateTimeImmutable $now): string => $profile === AgentProfile::ARTIFACTS
                ? SystemPrompt::artifacts($now, $input->appContext)
                : SystemPrompt::build($now),
        );
    }
}
