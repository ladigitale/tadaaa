<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Todo;
use App\Entity\User;
use App\Repository\GoogleCalendarBindingRepository;
use App\Repository\GoogleCalendarConnectionRepository;
use App\Repository\TodoRepository;
use Psr\Log\LoggerInterface;

/**
 * Outbound fan-out: for every user with export bindings on the dataset, sync the todo.
 */
final class GoogleCalendarSyncDispatcher
{
    public function __construct(
        private readonly GoogleCalendarBindingRepository $bindings,
        private readonly GoogleCalendarConnectionRepository $connections,
        private readonly TodoRepository $todos,
        private readonly GoogleCalendarSyncService $sync,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $todoIds
     */
    public function dispatchTodos(Dataset $dataset, array $todoIds): void
    {
        $todoIds = array_values(array_unique(array_filter($todoIds, static fn ($id) => is_string($id) && $id !== '')));
        if ($todoIds === []) {
            return;
        }

        $exportBindings = $this->bindings->findExportEnabledForDataset($dataset);
        if ($exportBindings === []) {
            return;
        }

        /** @var array<string, User> $users */
        $users = [];
        foreach ($exportBindings as $binding) {
            $uid = $binding->getUser()->getId()->toRfc4122();
            $users[$uid] = $binding->getUser();
        }

        foreach ($users as $user) {
            $connection = $this->connections->findOneByUser($user);
            if ($connection === null || !$connection->isActive()) {
                continue;
            }
            foreach ($todoIds as $todoId) {
                $todo = $this->todos->findOneForDataset($dataset, $todoId);
                if ($todo === null) {
                    continue;
                }
                try {
                    $this->sync->syncTodoForUser($user, $dataset, $todo);
                } catch (\Throwable $e) {
                    $this->logger->warning('Google Calendar dispatch failed: {message}', [
                        'message' => $e->getMessage(),
                        'todoId' => $todoId,
                    ]);
                }
            }
        }
    }

    public function dispatchTodo(Dataset $dataset, Todo $todo): void
    {
        $this->dispatchTodos($dataset, [$todo->getId()]);
    }
}
