<?php

declare(strict_types=1);

namespace App\Mcp\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Mcp\Tool\BulkUpdateTodosTool;
use App\Mcp\Tool\CreateTagTool;
use App\Mcp\Tool\CreateTodoTool;
use App\Mcp\Tool\DeleteTagTool;
use App\Mcp\Tool\ListDatasetsTool;
use App\Mcp\Tool\ListTagsTool;
use App\Mcp\Tool\ListTodosTool;
use App\Mcp\Tool\UpdateTagTool;
use App\Mcp\Tool\UpdateTodoTool;
use App\Service\CloudTodoService;
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
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $user = $this->requireUser();

        $payload = match (true) {
            $data instanceof ListDatasetsTool => ['datasets' => $this->todos->listDatasets($user)],
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
            ),
            $data instanceof CreateTagTool => $this->todos->createTag($user, $data->name, $data->color),
            $data instanceof UpdateTagTool => $this->updateTag($user, $data),
            $data instanceof DeleteTagTool => $this->todos->deleteTag($user, $data->id),
            $data instanceof UpdateTodoTool => $this->updateTodo($user, $data),
            $data instanceof BulkUpdateTodosTool => $this->bulkUpdate($user, $data),
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
