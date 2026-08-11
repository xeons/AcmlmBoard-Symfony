<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoardConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoardConfig>
 */
class BoardConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardConfig::class);
    }

    /**
     * The singleton settings row, created on first access so a fresh install has
     * working defaults rather than the original's behaviour of silently treating
     * every setting as 0 when the row was missing.
     */
    public function get(): BoardConfig
    {
        $config = $this->find(BoardConfig::SINGLETON_ID);

        if (null === $config) {
            $config = new BoardConfig();
            $this->getEntityManager()->persist($config);
            $this->getEntityManager()->flush();
        }

        return $config;
    }
}
