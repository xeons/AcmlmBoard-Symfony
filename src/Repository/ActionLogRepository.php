<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActionLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionLog>
 */
class ActionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionLog::class);
    }

    /**
     * @return Paginator<ActionLog>
     */
    public function paginate(int $page, int $perPage = 50, ?string $action = null): Paginator
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.actor', 'a')->addSelect('a')
            ->orderBy('l.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $action) {
            $qb->andWhere('l.action = :action')->setParameter('action', $action);
        }

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
