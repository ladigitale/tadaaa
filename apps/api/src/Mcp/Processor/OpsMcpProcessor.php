<?php

declare(strict_types=1);

namespace App\Mcp\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Mcp\Tool\GetProdUpdateStatusTool;
use App\Mcp\Tool\TriggerProdUpdateTool;
use App\Service\AuditLogger;
use App\Service\ProdDeploy;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Outils MCP d'exploitation (super admin) : mise à jour de production.
 *
 * Volontairement absents de l'agent intégré (ToolboxFactory) : seuls les clients MCP
 * authentifiés avec le compte super admin y ont accès.
 *
 * @implements ProcessorInterface<object, CallToolResult>
 */
final class OpsMcpProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProdDeploy $deploy,
        private readonly AuditLogger $audit,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }
        if (!$this->deploy->isAllowed($user)) {
            $this->safeAudit($user, 'ops.prod_update_denied', ['tool' => (string) $operation->getName()]);
            throw new AccessDeniedHttpException('Réservé au super admin.');
        }

        $payload = match (true) {
            $data instanceof TriggerProdUpdateTool => $this->trigger($user, $data),
            $data instanceof GetProdUpdateStatusTool => $this->deploy->status($user, $data->runId, $data->tail ?? 60),
            default => throw new \InvalidArgumentException(sprintf('Payload MCP non supporté : %s', get_debug_type($data))),
        };

        return new CallToolResult(
            [new TextContent(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')],
            false,
            $payload,
        );
    }

    /** @return array<string, mixed> */
    private function trigger(User $user, TriggerProdUpdateTool $data): array
    {
        $run = $this->deploy->trigger($user, $data->reason);
        $this->safeAudit($user, 'ops.prod_update_triggered', ['runId' => $run['id'], 'reason' => $run['reason']]);

        return $run;
    }

    /** @param array<string, mixed> $meta */
    private function safeAudit(User $user, string $action, array $meta): void
    {
        try {
            $this->audit->log($user, AuditLog::CATEGORY_MCP, $action, $meta);
        } catch (\Throwable) {
            // L'observabilité ne doit pas bloquer l'outil.
        }
    }
}
