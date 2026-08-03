<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Account-level link detectors: regexp tokens → URL templates ({id} = capture group 1).
 */
final class LinkDetectorService
{
    private const MAX_DETECTORS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, pattern: string, urlTemplate: string}>
     */
    public function list(User $user): array
    {
        return $user->getLinkDetectors();
    }

    /**
     * @return array{id: string, name: string, pattern: string, urlTemplate: string}
     */
    public function create(User $user, string $name, string $pattern, string $urlTemplate): array
    {
        $detectors = $user->getLinkDetectors();
        if (count($detectors) >= self::MAX_DETECTORS) {
            throw new BadRequestHttpException(sprintf(
                'Maximum %d link detectors per account.',
                self::MAX_DETECTORS,
            ));
        }

        $detector = $this->normalizeDetector([
            'id' => Uuid::v7()->toRfc4122(),
            'name' => $name,
            'pattern' => $pattern,
            'urlTemplate' => $urlTemplate,
        ]);
        $detectors[] = $detector;
        $user->setLinkDetectors($detectors);
        $this->entityManager->flush();

        return $detector;
    }

    /**
     * @param array{name?: string|null, pattern?: string|null, urlTemplate?: string|null} $patch
     *
     * @return array{id: string, name: string, pattern: string, urlTemplate: string}
     */
    public function update(User $user, string $id, array $patch): array
    {
        $detectors = $user->getLinkDetectors();
        $index = $this->indexOf($detectors, $id);
        if ($index === null) {
            throw new NotFoundHttpException('Link detector not found.');
        }

        $current = $detectors[$index];
        $next = $this->normalizeDetector([
            'id' => $current['id'],
            'name' => array_key_exists('name', $patch) && $patch['name'] !== null
                ? $patch['name']
                : $current['name'],
            'pattern' => array_key_exists('pattern', $patch) && $patch['pattern'] !== null
                ? $patch['pattern']
                : $current['pattern'],
            'urlTemplate' => array_key_exists('urlTemplate', $patch) && $patch['urlTemplate'] !== null
                ? $patch['urlTemplate']
                : $current['urlTemplate'],
        ]);
        $detectors[$index] = $next;
        $user->setLinkDetectors($detectors);
        $this->entityManager->flush();

        return $next;
    }

    /**
     * @return array{ok: true, id: string}
     */
    public function delete(User $user, string $id): array
    {
        $detectors = $user->getLinkDetectors();
        $index = $this->indexOf($detectors, $id);
        if ($index === null) {
            throw new NotFoundHttpException('Link detector not found.');
        }

        array_splice($detectors, $index, 1);
        $user->setLinkDetectors($detectors);
        $this->entityManager->flush();

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Replace the full list (web settings save).
     *
     * @param list<mixed> $raw
     *
     * @return list<array{id: string, name: string, pattern: string, urlTemplate: string}>
     */
    public function replaceAll(User $user, array $raw): array
    {
        if (count($raw) > self::MAX_DETECTORS) {
            throw new BadRequestHttpException(sprintf(
                'Maximum %d link detectors per account.',
                self::MAX_DETECTORS,
            ));
        }

        $seenIds = [];
        $detectors = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new BadRequestHttpException('Each link detector must be an object.');
            }
            $id = isset($item['id']) && is_string($item['id']) && trim($item['id']) !== ''
                ? trim($item['id'])
                : Uuid::v7()->toRfc4122();
            if (isset($seenIds[$id])) {
                throw new BadRequestHttpException('Duplicate link detector id.');
            }
            $seenIds[$id] = true;
            $detectors[] = $this->normalizeDetector([
                'id' => $id,
                'name' => $item['name'] ?? '',
                'pattern' => $item['pattern'] ?? '',
                'urlTemplate' => $item['urlTemplate'] ?? '',
            ]);
        }

        $user->setLinkDetectors($detectors);
        $this->entityManager->flush();

        return $detectors;
    }

    /**
     * @param list<array{id: string, name: string, pattern: string, urlTemplate: string}> $detectors
     */
    private function indexOf(array $detectors, string $id): ?int
    {
        foreach ($detectors as $i => $detector) {
            if ($detector['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array{id: string, name: mixed, pattern: mixed, urlTemplate: mixed} $raw
     *
     * @return array{id: string, name: string, pattern: string, urlTemplate: string}
     */
    private function normalizeDetector(array $raw): array
    {
        $name = is_string($raw['name']) ? trim($raw['name']) : '';
        $pattern = is_string($raw['pattern']) ? trim($raw['pattern']) : '';
        $urlTemplate = is_string($raw['urlTemplate']) ? trim($raw['urlTemplate']) : '';

        if ($name === '') {
            throw new BadRequestHttpException('Link detector name is required.');
        }
        if (mb_strlen($name) > 80) {
            throw new BadRequestHttpException('Link detector name is too long (max 80).');
        }
        if ($pattern === '') {
            throw new BadRequestHttpException('Link detector pattern is required.');
        }
        if (!str_contains($pattern, '(')) {
            throw new BadRequestHttpException(
                'Pattern must include a capturing group for the id (e.g. RM-(\\d+)).',
            );
        }
        $delimited = '/'.str_replace('/', '\\/', $pattern).'/';
        if (@preg_match($delimited, '') === false) {
            throw new BadRequestHttpException('Invalid regexp pattern.');
        }
        if ($urlTemplate === '' || !str_contains($urlTemplate, '{id}')) {
            throw new BadRequestHttpException('urlTemplate must include {id}.');
        }
        if (mb_strlen($urlTemplate) > 2048) {
            throw new BadRequestHttpException('urlTemplate is too long.');
        }

        return [
            'id' => $raw['id'],
            'name' => $name,
            'pattern' => $pattern,
            'urlTemplate' => $urlTemplate,
        ];
    }
}
