<?php

declare(strict_types=1);

namespace App\Mcp\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Mcp\Tool\ActivateDatasetTool;
use App\Mcp\Tool\BulkUpdateTodosTool;
use App\Mcp\Tool\CreateLinkDetectorTool;
use App\Mcp\Tool\CreateTagTool;
use App\Mcp\Tool\CopyTodoTool;
use App\Mcp\Tool\CreateTodoTool;
use App\Mcp\Tool\CreateWebhookTool;
use App\Mcp\Tool\DeleteLinkDetectorTool;
use App\Mcp\Tool\DeleteTagTool;
use App\Mcp\Tool\DeleteWebhookTool;
use App\Mcp\Tool\DescribeTextFormattingTool;
use App\Mcp\Tool\ListDatasetsTool;
use App\Mcp\Tool\ListLinkDetectorsTool;
use App\Mcp\Tool\ListTagsTool;
use App\Mcp\Tool\ListTodosTool;
use App\Mcp\Tool\ListWebhookDeliveriesTool;
use App\Mcp\Tool\ListWebhookEventsTool;
use App\Mcp\Tool\ListWebhooksTool;
use App\Mcp\Tool\UpdateLinkDetectorTool;
use App\Mcp\Tool\UpdateTagTool;
use App\Mcp\Tool\UpdateTodoTool;
use App\Mcp\Tool\UpdateWebhookTool;
use App\Service\AuditLogger;
use App\Service\CloudTodoService;
use App\Service\LinkDetectorService;
use App\Service\UsageMeter;
use App\Service\WebhookService;
use App\Webhook\WebhookEventType;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * State processor MCP : injection DI correcte (CallableProcessor ne résout pas
 * les services dans un callable static [Tool::class, 'process']).
 *
 * Retourne un CallToolResult pour éviter la normalisation JSON-LD qui transforme
 * les tableaux associatifs en Collections Hydra illisibles.
 *
 * @implements ProcessorInterface<object, CallToolResult>
 */
final class CloudTodoMcpProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CloudTodoService $todos,
        private readonly LinkDetectorService $linkDetectors,
        private readonly WebhookService $webhooks,
        private readonly AuditLogger $audit,
        private readonly UsageMeter $usage,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $user = $this->requireUser();
        $toolName = $this->toolName($data);
        $active = $user->getActiveDataset();

        try {
            $this->audit->log($user, AuditLog::CATEGORY_MCP, 'mcp.tool_called', [
                'tool' => $toolName,
                'datasetId' => $active?->getId()->toRfc4122(),
            ]);
            $this->usage->increment($user, $active, UsageMeter::MCP_CALLS);
        } catch (\Throwable) {
            // Observability must not break MCP.
        }

        $payload = match (true) {
            $data instanceof ListDatasetsTool => ['datasets' => $this->todos->listDatasets($user)],
            $data instanceof ActivateDatasetTool => $this->todos->activateDataset($user, $data->id),
            $data instanceof ListTagsTool => ['tags' => $this->todos->listTags($user)],
            $data instanceof ListTodosTool => [
                'todos' => $this->todos->listTodos(
                    $user,
                    $data->status,
                    $data->q,
                    max(1, min(200, $data->limit)),
                ),
            ],
            $data instanceof CreateTodoTool => $this->todos->createTodo(
                $user,
                $data->text,
                $data->description,
                $data->priority,
                $data->tagIds,
                $data->parentId,
                $data->startAt,
                $data->endAt,
                $data->recurrence,
            ),
            $data instanceof CopyTodoTool => $this->todos->copyTodo($user, $data->id, $data->includeChildren),
            $data instanceof CreateTagTool => $this->todos->createTag($user, $data->name, $data->color),
            $data instanceof UpdateTagTool => $this->updateTag($user, $data),
            $data instanceof DeleteTagTool => $this->todos->deleteTag($user, $data->id),
            $data instanceof UpdateTodoTool => $this->updateTodo($user, $data),
            $data instanceof BulkUpdateTodosTool => $this->bulkUpdate($user, $data),
            $data instanceof ListLinkDetectorsTool => [
                'linkDetectors' => $this->linkDetectors->list($user),
            ],
            $data instanceof CreateLinkDetectorTool => $this->linkDetectors->create(
                $user,
                $data->name,
                $data->pattern,
                $data->urlTemplate,
            ),
            $data instanceof UpdateLinkDetectorTool => $this->updateLinkDetector($user, $data),
            $data instanceof DeleteLinkDetectorTool => $this->linkDetectors->delete($user, $data->id),
            $data instanceof DescribeTextFormattingTool => $this->formattingGuide($user),
            $data instanceof ListWebhookEventsTool => ['events' => WebhookEventType::catalogue()],
            $data instanceof ListWebhooksTool => [
                'webhooks' => array_map(
                    $this->webhooks->serialize(...),
                    $this->webhooks->listForUser($user),
                ),
            ],
            $data instanceof CreateWebhookTool => $this->createWebhook($user, $data),
            $data instanceof UpdateWebhookTool => $this->updateWebhook($user, $data),
            $data instanceof DeleteWebhookTool => $this->deleteWebhook($user, $data),
            $data instanceof ListWebhookDeliveriesTool => [
                'deliveries' => array_map(
                    $this->webhooks->serializeDelivery(...),
                    $this->webhooks->listDeliveries($user, $data->id, max(1, min(200, $data->limit))),
                ),
            ],
            default => throw new \InvalidArgumentException(sprintf(
                'Payload MCP non supporté : %s',
                get_debug_type($data),
            )),
        };

        return new CallToolResult(
            [new TextContent($payload)],
            false,
            $payload,
        );
    }

    /** @return array<string, mixed> */
    private function createWebhook(User $user, CreateWebhookTool $data): array
    {
        $created = $this->webhooks->create($user, $data->url, $data->events, $data->datasetId);

        return [
            'webhook' => $this->webhooks->serialize($created['endpoint']),
            'plainSecret' => $created['plainSecret'],
        ];
    }

    /** @return array<string, mixed> */
    private function updateWebhook(User $user, UpdateWebhookTool $data): array
    {
        $patch = [];
        if ($data->url !== null) {
            $patch['url'] = $data->url;
        }
        if ($data->events !== null) {
            $patch['events'] = $data->events;
        }
        if ($data->active !== null) {
            $patch['active'] = $data->active;
        }
        if ($data->datasetId !== null) {
            $patch['datasetId'] = $data->datasetId === '' ? null : $data->datasetId;
        }

        return ['webhook' => $this->webhooks->serialize($this->webhooks->update($user, $data->id, $patch))];
    }

    /** @return array{ok: bool, id: string} */
    private function deleteWebhook(User $user, DeleteWebhookTool $data): array
    {
        $this->webhooks->delete($user, $data->id);

        return ['ok' => true, 'id' => $data->id];
    }

    private function toolName(mixed $data): string
    {
        return match (true) {
            $data instanceof ListDatasetsTool => 'list_datasets',
            $data instanceof ActivateDatasetTool => 'activate_dataset',
            $data instanceof ListTagsTool => 'list_tags',
            $data instanceof ListTodosTool => 'list_todos',
            $data instanceof CreateTodoTool => 'create_todo',
            $data instanceof CopyTodoTool => 'copy_todo',
            $data instanceof CreateTagTool => 'create_tag',
            $data instanceof UpdateTagTool => 'update_tag',
            $data instanceof DeleteTagTool => 'delete_tag',
            $data instanceof UpdateTodoTool => 'update_todo',
            $data instanceof BulkUpdateTodosTool => 'bulk_update_todos',
            $data instanceof ListLinkDetectorsTool => 'list_link_detectors',
            $data instanceof CreateLinkDetectorTool => 'create_link_detector',
            $data instanceof UpdateLinkDetectorTool => 'update_link_detector',
            $data instanceof DeleteLinkDetectorTool => 'delete_link_detector',
            $data instanceof DescribeTextFormattingTool => 'describe_text_formatting',
            $data instanceof ListWebhookEventsTool => 'list_webhook_events',
            $data instanceof ListWebhooksTool => 'list_webhooks',
            $data instanceof CreateWebhookTool => 'create_webhook',
            $data instanceof UpdateWebhookTool => 'update_webhook',
            $data instanceof DeleteWebhookTool => 'delete_webhook',
            $data instanceof ListWebhookDeliveriesTool => 'list_webhook_deliveries',
            default => get_debug_type($data),
        };
    }

    /** @return array<string, mixed> */
    private function formattingGuide(User $user): array
    {
        return [
            'descriptionMarkdown' => [
                'supported' => [
                    '**bold**',
                    '*italic* or _italic_',
                    '`inline code`',
                    '[label](https://example.com)',
                    'paragraphs (blank line)',
                    '- unordered lists',
                    '1. ordered lists',
                ],
                'notes' => [
                    'Markdown is rendered in the web UI for todo descriptions (not titles).',
                    'Raw HTML is escaped; only the subset above is interpreted.',
                ],
            ],
            'linkDetectors' => [
                'purpose' => 'Tokens matching account detectors become clickable links in titles and descriptions.',
                'manageWith' => [
                    'list_link_detectors',
                    'create_link_detector',
                    'update_link_detector',
                    'delete_link_detector',
                ],
                'current' => $this->linkDetectors->list($user),
                'fields' => [
                    'name' => 'Human label',
                    'pattern' => 'Regexp without flags; first capturing group is substituted as {id}',
                    'urlTemplate' => 'URL containing {id}, e.g. https://example.com/issues/{id}',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function updateLinkDetector(User $user, UpdateLinkDetectorTool $data): array
    {
        $patch = [];
        if ($data->name !== null) {
            $patch['name'] = $data->name;
        }
        if ($data->pattern !== null) {
            $patch['pattern'] = $data->pattern;
        }
        if ($data->urlTemplate !== null) {
            $patch['urlTemplate'] = $data->urlTemplate;
        }

        return $this->linkDetectors->update($user, $data->id, $patch);
    }

    /** @return array<string, mixed> */
    private function updateTag(User $user, UpdateTagTool $data): array
    {
        $patch = [];
        if ($data->name !== null) {
            $patch['name'] = $data->name;
        }
        if ($data->color !== null) {
            $patch['color'] = $data->color;
        }

        return $this->todos->updateTag($user, $data->id, $patch);
    }

    /** @return array<string, mixed> */
    private function updateTodo(User $user, UpdateTodoTool $data): array
    {
        $patch = [];
        if ($data->text !== null) {
            $patch['text'] = $data->text;
        }
        if ($data->description !== null) {
            $patch['description'] = $data->description;
        }
        if ($data->done !== null) {
            $patch['done'] = $data->done;
        }
        if ($data->archived !== null) {
            $patch['archived'] = $data->archived;
        }
        if ($data->priority !== null) {
            $patch['priority'] = $data->priority;
        }
        if ($data->tagIds !== null) {
            $patch['tagIds'] = $data->tagIds;
        }
        if ($data->parentId !== null) {
            $patch['parentId'] = $data->parentId === '' ? null : $data->parentId;
        }
        if ($data->startAt !== null) {
            $patch['startAt'] = $data->startAt === '' ? null : $data->startAt;
        }
        if ($data->endAt !== null) {
            $patch['endAt'] = $data->endAt === '' ? null : $data->endAt;
        }
        if ($data->recurrence !== null) {
            $patch['recurrence'] = $data->recurrence;
        }

        return $this->todos->updateTodo($user, $data->id, $patch);
    }

    /** @return array<string, mixed> */
    private function bulkUpdate(User $user, BulkUpdateTodosTool $data): array
    {
        $patch = [];
        if ($data->done !== null) {
            $patch['done'] = $data->done;
        }
        if ($data->archived !== null) {
            $patch['archived'] = $data->archived;
        }
        if ($data->priority !== null) {
            $patch['priority'] = $data->priority;
        }
        if ($data->tagIds !== null) {
            $patch['tagIds'] = $data->tagIds;
        }
        if ($patch === []) {
            throw new \InvalidArgumentException('Aucun champ à modifier (done, archived, priority, tagIds).');
        }

        return $this->todos->bulkUpdate(
            $user,
            $data->status,
            $data->q,
            $patch,
            max(1, min(200, $data->limit)),
        );
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }
}
