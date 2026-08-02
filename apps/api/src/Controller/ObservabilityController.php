<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Service\UsageMeter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ObservabilityController extends AbstractController
{
    public function __construct(
        private readonly AuditLogRepository $auditLogs,
        private readonly UsageMeter $usage,
    ) {
    }

    #[Route('/api/activity', name: 'api_activity', methods: ['GET'])]
    public function activity(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $category = $request->query->get('category');
        $category = is_string($category) && $category !== '' ? $category : null;
        if (
            $category !== null
            && !\in_array($category, [
                AuditLog::CATEGORY_WEBHOOK,
                AuditLog::CATEGORY_MCP,
                AuditLog::CATEGORY_TOKEN,
                AuditLog::CATEGORY_OAUTH,
                AuditLog::CATEGORY_USAGE,
            ], true)
        ) {
            $category = null;
        }
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));

        $rows = $this->auditLogs->findRecentForUser($user, $category, $limit);

        return $this->json([
            'member' => array_map(static fn (AuditLog $log) => [
                'id' => $log->getId()->toRfc4122(),
                'category' => $log->getCategory(),
                'action' => $log->getAction(),
                'meta' => $log->getMeta(),
                'ip' => $log->getIp(),
                'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $rows),
        ]);
    }

    #[Route('/api/usage', name: 'api_usage', methods: ['GET'])]
    public function usage(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $to = new \DateTimeImmutable('today');
        $from = $to->modify('-29 days');

        $fromRaw = $request->query->get('from');
        $toRaw = $request->query->get('to');
        if (is_string($fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)) {
            $from = new \DateTimeImmutable($fromRaw);
        }
        if (is_string($toRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)) {
            $to = new \DateTimeImmutable($toRaw);
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return $this->json($this->usage->report($user, $from, $to));
    }
}
