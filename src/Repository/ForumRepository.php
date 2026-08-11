<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Forum;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Forum>
 */
class ForumRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Forum::class);
    }

    /**
     * The whole index in one query: every forum the viewer may read, with its
     * category, last poster and moderator list already loaded.
     *
     * @return list<Forum>
     */
    public function findVisibleForIndex(int $power): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.category', 'c')->addSelect('c')
            ->leftJoin('f.lastPoster', 'lp')->addSelect('lp')
            ->leftJoin('f.moderators', 'm')->addSelect('m')
            ->leftJoin('m.user', 'mu')->addSelect('mu')
            ->andWhere('f.minPower <= 0 OR f.minPower <= :power')
            ->andWhere('c.minPower <= 0 OR c.minPower <= :power')
            ->setParameter('power', $power)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('f.position', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ids of every forum the viewer may read. Used to scope cross-forum listings
     * (search, posts-by-user, favorites) so restricted content cannot leak through
     * a view that forgot to check - the original leaked exactly that way in search.
     *
     * @return list<int>
     */
    public function findReadableIds(int $power): array
    {
        $ids = $this->createQueryBuilder('f')
            ->select('f.id')
            ->innerJoin('f.category', 'c')
            ->andWhere('f.minPower <= 0 OR f.minPower <= :power')
            ->andWhere('c.minPower <= 0 OR c.minPower <= :power')
            ->setParameter('power', $power)
            ->getQuery()
            ->getSingleColumnResult();

        // An empty IN () is a SQL error, so guarantee at least one impossible id.
        return $ids ?: [0];
    }

    /**
     * Forums for the jump menu.
     *
     * @return list<Forum>
     */
    public function findForJumpMenu(int $power): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.category', 'c')->addSelect('c')
            ->andWhere('f.minPower <= 0 OR f.minPower <= :power')
            ->andWhere('c.minPower <= 0 OR c.minPower <= :power')
            ->setParameter('power', $power)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('f.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Forum> */
    public function findModeratedBy(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.moderators', 'm')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findTrashForum(): ?Forum
    {
        return $this->findOneBy(['trash' => true]);
    }

    /** Recomputes the denormalised counters and last-post pointer for one forum. */
    public function recount(Forum $forum): void
    {
        $conn = $this->getEntityManager()->getConnection();

        $row = $conn->fetchAssociative(
            'SELECT COUNT(DISTINCT t.id) AS threads,
                    COALESCE(SUM(t.replies) + COUNT(DISTINCT t.id), 0) AS posts
             FROM threads t WHERE t.forum_id = :fid',
            ['fid' => $forum->getId()],
        ) ?: ['threads' => 0, 'posts' => 0];

        $forum->setThreadCount((int) $row['threads']);
        $forum->setPostCount((int) $row['posts']);

        $latest = $this->getEntityManager()
            ->getRepository(\App\Entity\Thread::class)
            ->findMostRecentInForum($forum);

        $forum->setLastPostAt($latest?->getLastPostAt());
        $forum->setLastPoster($latest?->getLastPoster());
    }
}
