<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IpBan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Storage only. Whether a given address is banned is decided by IpBanChecker, which
 * owns the caching and the subnet matching.
 *
 * @extends ServiceEntityRepository<IpBan>
 */
class IpBanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpBan::class);
    }

    /**
     * @return list<IpBan> every ban that has not expired
     */
    public function findActive(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return $this->createQueryBuilder('b')
            ->andWhere('b.expiresAt IS NULL OR b.expiresAt > :now')
            ->setParameter('now', $now)
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
