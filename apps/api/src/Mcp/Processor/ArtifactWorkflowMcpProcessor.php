<?php

declare(strict_types=1);

namespace App\Mcp\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Mcp\RawToolArguments;
use App\Mcp\Tool\DeleteArtifactWorkflowTool;
use App\Mcp\Tool\GetArtifactWorkflowRunTool;
use App\Mcp\Tool\ListArtifactWorkflowsTool;
use App\Mcp\Tool\RunArtifactWorkflowTool;
use App\Mcp\Tool\SaveArtifactWorkflowTool;
use App\Service\ArtifactWorkflowService;
use App\Service\AuditLogger;
use App\Service\UsageMeter;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Outils MCP des workflows d'artefacts (enchaînement d'artefacts-outils).
 *
 * @implements ProcessorInterface<object, CallToolResult>
 */
final class ArtifactWorkflowMcpProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ArtifactWorkflowService $workflows,
        private readonly Security $security,
        private readonly RawToolArguments $rawArguments,
        private readonly AuditLogger $audit,
        private readonly UsageMeter $usage,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }
        try {
            $this->audit->log($user, AuditLog::CATEGORY_ARTIFACT, 'mcp.artifact_tool', ['tool' => (string) $operation->getName()]);
            $this->usage->increment($user, $user->getActiveDataset(), UsageMeter::MCP_CALLS);
        } catch (\Throwable) {
        }

        $slug = static fn (?string $s): string => \is_string($s) && trim($s) !== '' ? trim($s) : throw new BadRequestHttpException('slug requis.');
        $payload = match (true) {
            $data instanceof SaveArtifactWorkflowTool => $this->workflows->save(
                $user,
                $slug($data->slug),
                $this->rawArguments->rawArgument($context, 'save_artifact_workflow', 'definition') ?? $data->definition ?? throw new BadRequestHttpException('definition requise.'),
            ),
            $data instanceof ListArtifactWorkflowsTool => \is_string($data->slug) && $data->slug !== ''
                ? $this->workflows->get($user, $data->slug)
                : ['workflows' => $this->workflows->list($user)],
            $data instanceof DeleteArtifactWorkflowTool => (function () use ($user, $data, $slug): array {
                $this->workflows->delete($user, $slug($data->slug));

                return ['deleted' => true];
            })(),
            $data instanceof RunArtifactWorkflowTool => $this->workflows->start(
                $user,
                $slug($data->slug),
                $this->rawArguments->rawArgument($context, 'run_artifact_workflow', 'input')
                    ?? json_decode((string) json_encode($data->input ?? new \stdClass()), false),
            ),
            $data instanceof GetArtifactWorkflowRunTool => (function () use ($user, $data): array {
                $id = \is_string($data->workflowRunId) && $data->workflowRunId !== '' ? $data->workflowRunId : throw new BadRequestHttpException('workflowRunId requis.');

                return $data->cancel === true ? $this->workflows->cancelRun($user, $id) : $this->workflows->getRun($user, $id);
            })(),
            default => throw new \InvalidArgumentException(sprintf('Payload MCP workflow non supporté : %s', get_debug_type($data))),
        };

        $text = (string) json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);

        return new CallToolResult([new TextContent($text)], false, $payload);
    }
}
