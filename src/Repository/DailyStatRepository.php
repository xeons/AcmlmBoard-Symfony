<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DailyStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyStat>
 */
class DailyStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyStat::class);
    }

    /**
     * Writes today's cumulative snapshot. One upsert, run by a scheduled command -
     * the original ran an INSERT plus an UPDATE on every page load of every visitor.
     */
    public function record(\DateTimeImmutable $day, int $users, int $threads, int $posts, int $views): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO daily_stats (date, users, threads, posts, views)
             VALUES (:date, :users, :threads, :posts, :views)
             ON DUPLICATE KEY UPDATE
                users = VALUES(users), threads = VALUES(threads),
                posts = VALUES(posts), views = VALUES(views)',
            [
                'date' => $day->format('Y-m-d'),
                'users' => $users,
                'threads' => $threads,
                'posts' => $posts,
                'views' => $views,
            ],
        );
    }

    /** @return list<DailyStat> oldest first, so the view can diff consecutive rows */
    public function findRecent(int $days = 60): array
    {
        $rows = $this->createQueryBuilder('s')
            ->orderBy('s.date', 'DESC')
            ->setMaxResults($days)
            ->getQuery()
            ->getResult();

        return array_reverse($rows);
    }
}
