<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SoftBan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SoftBan>
 */
class SoftBanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SoftBan::class);
    }

    public function findActiveFor(User $user, ?\DateTimeImmutable $now = null): ?SoftBan
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.expiresAt IS NULL OR b.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->orderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<SoftBan> */
    public function findAllActive(?\DateTimeImmutable $now = null): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.user', 'u')->addSelect('u')
            ->andWhere('b.expiresAt IS NULL OR b.expiresAt > :now')
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function purgeExpired(?\DateTimeImmutable $now = null): int
    {
        return (int) $this->createQueryBuilder('b')
            ->delete()
            ->andWhere('b.expiresAt IS NOT NULL AND b.expiresAt <= :now')
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
