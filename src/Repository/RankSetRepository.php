<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RankSet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RankSet>
 */
class RankSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RankSet::class);
    }

    /**
     * All rank sets with their rungs preloaded - the profile form and the ranks page
     * both need the whole ladder, and there are only a handful of rows.
     *
     * @return list<RankSet>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('rs')
            ->leftJoin('rs.ranks', 'r')->addSelect('r')
            ->orderBy('rs.position', 'ASC')
            ->addOrderBy('rs.id', 'ASC')
            ->addOrderBy('r.minPosts', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<RankSet> */
    public function findPercentileBased(): array
    {
        return $this->createQueryBuilder('rs')
            ->leftJoin('rs.ranks', 'r')->addSelect('r')
            ->andWhere('rs.percentileBased = true')
            ->getQuery()
            ->getResult();
    }
}
