<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Forum;
use App\Entity\ForumBan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumBan>
 */
class ForumBanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumBan::class);
    }

    public function isBanned(User $user, Forum $forum, ?\DateTimeImmutable $now = null): bool
    {
        return null !== $this->createQueryBuilder('b')
            ->select('b.id')
            ->andWhere('b.user = :user')
            ->andWhere('b.forum = :forum')
            ->andWhere('b.expiresAt IS NULL OR b.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('forum', $forum)
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ForumBan> */
    public function findActiveFor(User $user, ?\DateTimeImmutable $now = null): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.forum', 'f')->addSelect('f')
            ->andWhere('b.user = :user')
            ->andWhere('b.expiresAt IS NULL OR b.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function findOneFor(User $user, Forum $forum): ?ForumBan
    {
        return $this->findOneBy(['user' => $user, 'forum' => $forum]);
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
