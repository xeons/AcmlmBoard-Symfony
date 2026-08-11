<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Announcement;
use App\Entity\Forum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Announcement>
 */
class AnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Announcement::class);
    }

    public function findLatestGlobal(): ?Announcement
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.author', 'u')->addSelect('u')
            ->andWhere('a.forum IS NULL')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestForForum(Forum $forum): ?Announcement
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.author', 'u')->addSelect('u')
            ->andWhere('a.forum = :forum')
            ->setParameter('forum', $forum)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Announcement> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.author', 'u')->addSelect('u')
            ->leftJoin('a.forum', 'f')->addSelect('f')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
