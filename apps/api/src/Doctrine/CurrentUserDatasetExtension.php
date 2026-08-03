<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Dataset;
use App\Entity\DatasetMember;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

final class CurrentUserDatasetExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->addAccessFilter($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->addAccessFilter($queryBuilder, $resourceClass);
    }

    private function addAccessFilter(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== Dataset::class) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $memberAlias = 'access_member';
        $queryBuilder
            ->leftJoin(
                DatasetMember::class,
                $memberAlias,
                'WITH',
                sprintf('%s.dataset = %s AND %s.user = :current_user', $memberAlias, $rootAlias, $memberAlias),
            )
            ->andWhere(sprintf('%s.owner = :current_user OR %s.id IS NOT NULL', $rootAlias, $memberAlias))
            ->setParameter('current_user', $user);
    }
}
