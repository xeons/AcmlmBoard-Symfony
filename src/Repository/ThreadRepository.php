<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Forum;
use App\Entity\Thread;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Thread>
 */
class ThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Thread::class);
    }

    /**
     * One page of a forum's thread list, stickies first then newest activity.
     *
     * Every row needs the starter and the last poster, so both are joined and
     * selected up front; the original issued a three-table cartesian join per page
     * and then a fresh query per thread for the page links.
     *
     * @return Paginator<Thread>
     */
    public function paginateForum(Forum $forum, int $page, int $perPage): Paginator
    {
        $query = $this->createQueryBuilder('t')
            ->leftJoin('t.author', 'a')->addSelect('a')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->leftJoin('t.poll', 'p')->addSelect('p')
            ->andWhere('t.forum = :forum')
            ->setParameter('forum', $forum)
            ->orderBy('t.sticky', 'DESC')
            ->addOrderBy('t.lastPostAt', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        return new Paginator($query, fetchJoinCollection: false);
    }

    /**
     * Threads started by one user, across every forum they can still see.
     *
     * @param list<int> $visibleForumIds
     *
     * @return Paginator<Thread>
     */
    public function paginateByAuthor(User $author, array $visibleForumIds, int $page, int $perPage): Paginator
    {
        $query = $this->createQueryBuilder('t')
            ->leftJoin('t.author', 'a')->addSelect('a')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->leftJoin('t.forum', 'f')->addSelect('f')
            ->andWhere('t.author = :author')
            ->andWhere('t.forum IN (:forums)')
            ->setParameter('author', $author)
            ->setParameter('forums', $visibleForumIds)
            ->orderBy('t.lastPostAt', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        return new Paginator($query, fetchJoinCollection: false);
    }

    /**
     * @param list<int> $visibleForumIds
     *
     * @return Paginator<Thread>
     */
    public function paginateFavorites(User $user, array $visibleForumIds, int $page, int $perPage): Paginator
    {
        $query = $this->createQueryBuilder('t')
            ->innerJoin(\App\Entity\Favorite::class, 'fav', 'WITH', 'fav.thread = t AND fav.user = :user')
            ->leftJoin('t.author', 'a')->addSelect('a')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->leftJoin('t.forum', 'f')->addSelect('f')
            ->andWhere('t.forum IN (:forums)')
            ->setParameter('user', $user)
            ->setParameter('forums', $visibleForumIds)
            ->orderBy('t.sticky', 'DESC')
            ->addOrderBy('t.lastPostAt', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        return new Paginator($query, fetchJoinCollection: false);
    }

    /** The thread with the next-newer last post in the same forum, for the nav links. */
    public function findNewerSibling(Thread $thread): ?Thread
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.forum = :forum')
            ->andWhere('t.lastPostAt > :at')
            ->setParameter('forum', $thread->getForum())
            ->setParameter('at', $thread->getLastPostAt())
            ->orderBy('t.lastPostAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOlderSibling(Thread $thread): ?Thread
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.forum = :forum')
            ->andWhere('t.lastPostAt < :at')
            ->setParameter('forum', $thread->getForum())
            ->setParameter('at', $thread->getLastPostAt())
            ->orderBy('t.lastPostAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Most recent activity in a forum, used to repair the forum's denormalised
     * last-post columns after a thread is trashed or deleted.
     */
    public function findMostRecentInForum(Forum $forum): ?Thread
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.forum = :forum')
            ->setParameter('forum', $forum)
            ->orderBy('t.lastPostAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.author = :author')
            ->setParameter('author', $author)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countInForum(Forum $forum): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.forum = :forum')
            ->setParameter('forum', $forum)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Atomic view increment. Deliberately bypasses the ORM: the alternative is to
     * read, mutate and flush, which loses concurrent increments and dirties the
     * thread on every page view.
     */
    public function incrementViews(Thread $thread): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE threads SET views = views + 1 WHERE id = :id',
            ['id' => $thread->getId()],
        );
    }

    /**
     * Latest threads for a forum's feed.
     *
     * @return list<Thread>
     */
    public function findLatestForFeed(?Forum $forum, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.author', 'a')->addSelect('a')
            ->orderBy('t.lastPostAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $forum) {
            $qb->andWhere('t.forum = :forum')->setParameter('forum', $forum);
        }

        return $qb->getQuery()->getResult();
    }
}
