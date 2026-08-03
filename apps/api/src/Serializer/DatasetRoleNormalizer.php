<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Dataset;
use App\Entity\User;
use App\Service\DatasetAccessService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds `role` (owner|writer|reader) for the current user on Dataset JSON.
 */
final class DatasetRoleNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'dataset_role_normalizer_already_called';

    public function __construct(
        private readonly DatasetAccessService $access,
        private readonly Security $security,
    ) {
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::ALREADY_CALLED] = true;
        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizer->normalize($object, $format, $context);

        if (!$object instanceof Dataset || !is_array($normalized)) {
            return $normalized;
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $role = $this->access->getRole($user, $object);
            if ($role !== null) {
                $normalized['role'] = $role->value;
            }
        }

        return $normalized;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if ($context[self::ALREADY_CALLED] ?? false) {
            return false;
        }

        return $data instanceof Dataset;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Dataset::class => false];
    }
}
