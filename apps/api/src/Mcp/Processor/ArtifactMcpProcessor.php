<?php

declare(strict_types=1);

namespace App\Mcp\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Mcp\RawToolArguments;
use App\Mcp\Tool\CloseArtifactIntakeTool;
use App\Mcp\Tool\DeleteArtifactTool;
use App\Mcp\Tool\GetArtifactCatalogTool;
use App\Mcp\Tool\GetArtifactTool;
use App\Mcp\Tool\ListArtifactsTool;
use App\Mcp\Tool\OpenArtifactIntakeTool;
use App\Mcp\Tool\PublishArtifactTool;
use App\Mcp\Tool\ReadArtifactDataTool;
use App\Mcp\Tool\UpdateArtifactTool;
use App\Mcp\Tool\ValidateArtifactTool;
use App\Mcp\Tool\WriteArtifactDataTool;
use App\Service\ArtifactDataService;
use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactService;
use App\Service\AuditLogger;
use App\Service\DatasetAccessService;
use App\Service\UsageMeter;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<object, CallToolResult>
 */
final class ArtifactMcpProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly ArtifactDocumentValidator $validator,
        private readonly ArtifactDataService $data,
        private readonly DatasetAccessService $datasetAccess,
        private readonly AuditLogger $audit,
        private readonly UsageMeter $usage,
        private readonly Security $security,
        private readonly RawToolArguments $rawArguments,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $user = $this->requireUser();
        $toolName = $this->toolName($data);
        $active = $user->getActiveDataset();

        try {
            $this->audit->log($user, AuditLog::CATEGORY_ARTIFACT, 'mcp.artifact_tool', [
                'tool' => $toolName,
                'datasetId' => $active?->getId()->toRfc4122(),
            ]);
            $this->usage->increment($user, $active, UsageMeter::MCP_CALLS);
        } catch (\Throwable) {
        }

        $payload = match (true) {
            $data instanceof GetArtifactCatalogTool => $this->validator->mcpCatalogPayload(
                $data->compact,
                $data->components,
            ),
            $data instanceof ValidateArtifactTool => $this->validator->validate($data->document ?? []),
            $data instanceof PublishArtifactTool => $this->publish($user, $data, $context),
            $data instanceof UpdateArtifactTool => $this->update($user, $data, $context),
            $data instanceof GetArtifactTool => $this->get($user, $data),
            $data instanceof ListArtifactsTool => [
                'artifacts' => $this->artifacts->listForUser($user, $this->emptyToNull($data->datasetId)),
            ],
            $data instanceof DeleteArtifactTool => $this->delete($user, $data),
            $data instanceof ReadArtifactDataTool => [
                'records' => $this->data->readForUser($user, $data->slug, $data->collection),
            ],
            $data instanceof WriteArtifactDataTool => $this->writeData($user, $data),
            $data instanceof OpenArtifactIntakeTool => $this->openIntake($user, $data),
            $data instanceof CloseArtifactIntakeTool => $this->closeIntake($user, $data),
            default => throw new \InvalidArgumentException(sprintf(
                'Payload MCP artefact non supporté : %s',
                get_debug_type($data),
            )),
        };

        return new CallToolResult(
            [new TextContent($payload)],
            false,
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function publish(User $user, PublishArtifactTool $tool, array $context): array
    {
        $document = $tool->document;
        if (!\is_array($document) || $document === []) {
            throw new BadRequestHttpException('document requis (objet JSON SDUI).');
        }

        $datasetId = $this->emptyToNull($tool->datasetId);
        if ($datasetId === null) {
            $datasetId = $this->artifacts->artifactStoreFor($user)->getId()->toRfc4122();
        }

        $created = $this->artifacts->create(
            $user,
            $datasetId,
            $tool->title,
            $document,
            $this->emptyToNull($tool->slug),
            $tool->visibility !== '' ? $tool->visibility : 'private',
            $this->emptyToNull($tool->description),
            null,
            $this->rawArguments->emptyObjects($context, 'publish_artifact', 'document'),
        );

        return [
            'id' => $created['id'],
            'slug' => $created['slug'],
            'url' => $created['url'],
            'version' => $created['version'] ?? $created['currentVersion'] ?? 1,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function update(User $user, UpdateArtifactTool $tool, array $context): array
    {
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('id ou slug requis.');
        }

        $result = null;
        if ($tool->title !== null || $tool->visibility !== null || $tool->description !== null) {
            $patch = [];
            if ($tool->title !== null) {
                $patch['title'] = $tool->title;
            }
            if ($tool->visibility !== null) {
                $patch['visibility'] = $tool->visibility;
            }
            if ($tool->description !== null) {
                $patch['description'] = $tool->description;
            }
            $result = $this->artifacts->patch($user, $idOrSlug, $patch);
        }

        if (\is_array($tool->document) && $tool->document !== []) {
            $result = $this->artifacts->putDocument(
                $user,
                $idOrSlug,
                $tool->document,
                $this->emptyToNull($tool->note),
                null,
                $this->rawArguments->emptyObjects($context, 'update_artifact', 'document'),
            );
        }

        if ($result === null) {
            throw new BadRequestHttpException('Rien à mettre à jour (document, title, visibility ou description).');
        }

        return [
            'id' => $result['id'],
            'slug' => $result['slug'],
            'url' => $result['url'],
            'version' => $result['version'] ?? $result['currentVersion'],
        ];
    }

    /** @return array<string, mixed> */
    private function get(User $user, GetArtifactTool $tool): array
    {
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('id ou slug requis.');
        }

        return $this->artifacts->getForUser($user, $idOrSlug, $tool->version);
    }

    /** @return array<string, mixed> */
    private function delete(User $user, DeleteArtifactTool $tool): array
    {
        $idOrSlug = $this->resolveIdOrSlug($tool->id, $tool->slug);
        if ($idOrSlug === null) {
            throw new BadRequestHttpException('id ou slug requis.');
        }
        $this->artifacts->delete($user, $idOrSlug);

        return ['ok' => true, 'idOrSlug' => $idOrSlug];
    }

    /** @return array<string, mixed> */
    private function writeData(User $user, WriteArtifactDataTool $tool): array
    {
        if ($tool->slug === '' || $tool->collection === '') {
            throw new BadRequestHttpException('slug et collection requis.');
        }
        if (!\is_array($tool->data)) {
            throw new BadRequestHttpException('data requis (objet JSON).');
        }

        if ($tool->recordId !== null && $tool->recordId !== '') {
            $updated = $this->data->updateRecord($user, $tool->slug, $tool->collection, $tool->recordId, $tool->data);

            return ['action' => 'updated', 'record' => $updated];
        }

        $created = $this->data->createRecord($user, $tool->slug, $tool->collection, $tool->data);

        return ['action' => 'created', 'record' => $created];
    }

    /** @return array<string, mixed> */
    private function openIntake(User $user, OpenArtifactIntakeTool $tool): array
    {
        if ($tool->slug === '' || $tool->collection === '') {
            throw new BadRequestHttpException('slug et collection requis.');
        }

        return ['collection' => $this->data->openIntake($user, $tool->slug, $tool->collection, $tool->minutes ?? 60, $tool->maxRecords)];
    }

    /** @return array<string, mixed> */
    private function closeIntake(User $user, CloseArtifactIntakeTool $tool): array
    {
        if ($tool->slug === '' || $tool->collection === '') {
            throw new BadRequestHttpException('slug et collection requis.');
        }
        $state = $this->data->closeIntake($user, $tool->slug, $tool->collection, $tool->purge);
        if ($tool->rotateReadToken) {
            $state = $this->data->rotateReadToken($user, $tool->slug, $tool->collection);
        }

        return ['collection' => $state];
    }

    private function resolveIdOrSlug(?string $id, ?string $slug): ?string
    {
        $id = $this->emptyToNull($id);
        $slug = $this->emptyToNull($slug);
        if ($id !== null) {
            return $id;
        }

        return $slug;
    }

    private function emptyToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }

    private function toolName(mixed $data): string
    {
        return match (true) {
            $data instanceof GetArtifactCatalogTool => 'get_artifact_catalog',
            $data instanceof ValidateArtifactTool => 'validate_artifact',
            $data instanceof PublishArtifactTool => 'publish_artifact',
            $data instanceof UpdateArtifactTool => 'update_artifact',
            $data instanceof GetArtifactTool => 'get_artifact',
            $data instanceof ListArtifactsTool => 'list_artifacts',
            $data instanceof DeleteArtifactTool => 'delete_artifact',
            $data instanceof ReadArtifactDataTool => 'read_artifact_data',
            $data instanceof WriteArtifactDataTool => 'write_artifact_data',
            $data instanceof OpenArtifactIntakeTool => 'open_artifact_intake',
            $data instanceof CloseArtifactIntakeTool => 'close_artifact_intake',
            default => get_debug_type($data),
        };
    }
}
