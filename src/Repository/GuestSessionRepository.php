<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Forum;
use App\Entity\GuestSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestSession>
 */
class GuestSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestSession::class);
    }

    /**
     * Records a guest hit as a single upsert, replacing the original's
     * DELETE-then-INSERT pair. Written through the connection so it costs one
     * statement and cannot conflict with a concurrent request from the same address.
     */
    public function touch(string $ip, ?string $url, ?Forum $forum, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO guest_sessions (ip, last_seen_at, last_url, current_forum_id)
             VALUES (:ip, :now, :url, :forum)
             ON DUPLICATE KEY UPDATE
                last_seen_at = VALUES(last_seen_at),
                last_url = VALUES(last_url),
                current_forum_id = VALUES(current_forum_id)',
            [
                'ip' => $ip,
                'now' => $now->format('Y-m-d H:i:s'),
                'url' => null === $url ? null : mb_substr($url, 0, 255),
                'forum' => $forum?->getId(),
            ],
        );
    }

    public function countActive(\DateTimeImmutable $since, ?Forum $forum = null): int
    {
        $qb = $this->createQueryBuilder('g')
            ->select('COUNT(g.ip)')
            ->andWhere('g.lastSeenAt > :since')
            ->setParameter('since', $since);

        if (null !== $forum) {
            $qb->andWhere('g.currentForum = :forum')->setParameter('forum', $forum);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function purgeStale(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('g')
            ->delete()
            ->andWhere('g.lastSeenAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
