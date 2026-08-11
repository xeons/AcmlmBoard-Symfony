<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoardStats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoardStats>
 */
class BoardStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardStats::class);
    }

    public function get(): BoardStats
    {
        $stats = $this->find(BoardStats::SINGLETON_ID);

        if (null === $stats) {
            $stats = new BoardStats();
            $this->getEntityManager()->persist($stats);
            $this->getEntityManager()->flush();
        }

        return $stats;
    }

    /**
     * Bumps the global page-view counter and returns the new value.
     *
     * The original did `SELECT views`, added one in PHP, then `UPDATE misc SET
     * views=$views` - a read-modify-write with no locking, so concurrent requests
     * overwrote each other and the counter drifted. This is a single atomic UPDATE.
     */
    public function incrementPageViews(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement(
            'UPDATE board_stats SET page_views = page_views + 1 WHERE id = :id',
            ['id' => BoardStats::SINGLETON_ID],
        );

        return (int) $conn->fetchOne(
            'SELECT page_views FROM board_stats WHERE id = :id',
            ['id' => BoardStats::SINGLETON_ID],
        );
    }
}
