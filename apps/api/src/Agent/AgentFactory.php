<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Llm\LlmClient;
use Psr\Log\LoggerInterface;

final class AgentFactory
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolboxFactory $toolboxes,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(string $profile): AgentRunner
    {
        return new AgentRunner(
            $this->llm,
            $this->toolboxes->create($profile),
            $this->logger,
            systemPrompt: static fn (RunInput $input, \DateTimeImmutable $now): string => $profile === AgentProfile::ARTIFACTS
                ? SystemPrompt::artifacts($now, $input->appContext)
                : SystemPrompt::build($now),
        );
    }
}
