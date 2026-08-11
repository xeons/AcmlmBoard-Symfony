<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Forum;
use App\Entity\Post;
use App\Entity\Thread;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * One page of a thread.
     *
     * Rendering a post needs the author plus their rank set and layout snapshots.
     * Fetching those eagerly turns what was 4 + 3n queries per page into one.
     *
     * @return Paginator<Post>
     */
    public function paginateThread(Thread $thread, int $page, int $perPage): Paginator
    {
        $query = $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->leftJoin('a.rankSet', 'rs')->addSelect('rs')
            ->leftJoin('p.headerLayout', 'hl')->addSelect('hl')
            ->leftJoin('p.signatureLayout', 'sl')->addSelect('sl')
            ->leftJoin('p.editedBy', 'eb')->addSelect('eb')
            ->andWhere('p.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('p.id', 'ASC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        return new Paginator($query, fetchJoinCollection: false);
    }

    /**
     * All posts by one user, newest first.
     *
     * @param list<int> $visibleForumIds
     *
     * @return Paginator<Post>
     */
    public function paginateByAuthor(User $author, array $visibleForumIds, int $page, int $perPage): Paginator
    {
        $query = $this->baseRenderQuery()
            ->innerJoin('p.thread', 't')->addSelect('t')
            ->andWhere('p.author = :author')
            ->andWhere('t.forum IN (:forums)')
            ->setParameter('author', $author)
            ->setParameter('forums', $visibleForumIds)
            ->orderBy('p.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        return new Paginator($query, fetchJoinCollection: false);
    }

    /**
     * 1-based index of a post within its thread, so a permalink can be turned into a
     * page number. The original loaded *every* post id in the thread into PHP and
     * walked the array; this is a single COUNT.
     */
    public function getPositionInThread(Post $post): int
    {
        return 1 + (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.thread = :thread')
            ->andWhere('p.id < :id')
            ->setParameter('thread', $post->getThread())
            ->setParameter('id', $post->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findFirstInThread(Thread $thread): ?Post
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLastInThread(Thread $thread): ?Post
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('p.thread = :thread')
            ->setParameter('thread', $thread)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.createdAt > :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.author = :author')
            ->setParameter('author', $author)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Posts made by one user in one thread since a cut-off - the per-thread flood check. */
    public function countByAuthorInThreadSince(User $author, Thread $thread, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.author = :author')
            ->andWhere('p.thread = :thread')
            ->andWhere('p.createdAt > :since')
            ->setParameter('author', $author)
            ->setParameter('thread', $thread)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Posts-in-the-last-day per user, for the activity indicator in the post layout.
     *
     * @return array<int, int> user id => post count
     */
    public function getRecentActivityByUser(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.author) AS uid, COUNT(p.id) AS num')
            ->andWhere('p.createdAt > :since')
            ->andWhere('p.author IS NOT NULL')
            ->setParameter('since', $since)
            ->groupBy('p.author')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['uid']] = (int) $row['num'];
        }

        return $out;
    }

    /**
     * Full-text-ish post search.
     *
     * The original built this by string-concatenating user input into the WHERE
     * clause - `text LIKE '%$qmsg%'` and `ip LIKE '$qip'` with only addslashes(), and
     * `posts.user=$u` with no escaping at all. Everything here is a bound parameter.
     *
     * @param list<int> $visibleForumIds
     *
     * @return Paginator<Post>
     */
    /**
     * Escapes LIKE metacharacters, using '=' as the escape character.
     *
     * DQL requires the ESCAPE character as a literal, not a parameter. '=' is chosen
     * because it is unremarkable to both DQL and the database, so it needs no
     * quoting games in either layer as a backslash would.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }

    public function search(
        array $visibleForumIds,
        ?string $text = null,
        ?User $author = null,
        ?string $ip = null,
        ?Forum $forum = null,
        ?\DateTimeImmutable $after = null,
        ?\DateTimeImmutable $before = null,
        bool $newestFirst = true,
        int $page = 1,
        int $perPage = 20,
    ): Paginator {
        $qb = $this->baseRenderQuery()
            ->innerJoin('p.thread', 't')->addSelect('t')
            ->andWhere('t.forum IN (:forums)')
            ->setParameter('forums', $visibleForumIds);

        if (null !== $text && '' !== trim($text)) {
            // LIKE metacharacters in user input are escaped so a search for "100%"
            // does not silently become a wildcard.
            $qb->andWhere("p.body LIKE :text ESCAPE '='")
                ->setParameter('text', '%'.self::escapeLike($text).'%');
        }
        if (null !== $author) {
            $qb->andWhere('p.author = :author')->setParameter('author', $author);
        }
        if (null !== $ip && '' !== trim($ip)) {
            $qb->andWhere("p.ip LIKE :ip ESCAPE '='")
                ->setParameter('ip', self::escapeLike($ip).'%');
        }
        if (null !== $forum) {
            $qb->andWhere('t.forum = :forum')->setParameter('forum', $forum);
        }
        if (null !== $after) {
            $qb->andWhere('p.createdAt >= :after')->setParameter('after', $after);
        }
        if (null !== $before) {
            $qb->andWhere('p.createdAt <= :before')->setParameter('before', $before);
        }

        $query = $qb
            ->orderBy('p.id', $newestFirst ? 'DESC' : 'ASC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        return new Paginator($query, fetchJoinCollection: false);
    }

    /** @return list<Post> */
    public function findLatestForFeed(?Thread $thread, int $limit = 20): array
    {
        $qb = $this->baseRenderQuery()
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $thread) {
            $qb->andWhere('p.thread = :thread')->setParameter('thread', $thread);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Post counts per forum, newest activity first.
     *
     * @param list<int> $visibleForumIds
     *
     * @return list<array{forum: Forum, count: int}>
     */
    public function countByForum(array $visibleForumIds, ?User $author = null, ?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('f.id AS id, COUNT(p.id) AS cnt')
            ->innerJoin('p.thread', 't')
            ->innerJoin('t.forum', 'f')
            ->andWhere('f.id IN (:forums)')->setParameter('forums', $visibleForumIds)
            ->groupBy('f.id')
            ->orderBy('cnt', 'DESC');

        $this->applyScope($qb, $author, $since);

        return $this->hydrateCounts($qb->getQuery()->getResult(), Forum::class, 'forum');
    }

    /**
     * Post counts per thread for one member.
     *
     * @param list<int> $visibleForumIds
     *
     * @return list<array{thread: Thread, count: int}>
     */
    public function countByThread(array $visibleForumIds, User $author, ?\DateTimeImmutable $since = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('t.id AS id, COUNT(p.id) AS cnt')
            ->innerJoin('p.thread', 't')
            ->innerJoin('t.forum', 'f')
            ->andWhere('f.id IN (:forums)')->setParameter('forums', $visibleForumIds)
            ->groupBy('t.id')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit);

        $this->applyScope($qb, $author, $since);

        return $this->hydrateCounts($qb->getQuery()->getResult(), Thread::class, 'thread');
    }

    /**
     * Posts per member between two instants, busiest first.
     *
     * @param list<int> $visibleForumIds
     *
     * @return list<array{user: User, count: int}>
     */
    public function countByAuthorBetween(
        array $visibleForumIds,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->createQueryBuilder('p')
            ->select('a.id AS id, COUNT(p.id) AS cnt')
            ->innerJoin('p.thread', 't')
            ->innerJoin('t.forum', 'f')
            ->innerJoin('p.author', 'a')
            ->andWhere('f.id IN (:forums)')->setParameter('forums', $visibleForumIds)
            ->andWhere('p.createdAt >= :from')->setParameter('from', $from)
            ->andWhere('p.createdAt < :to')->setParameter('to', $to)
            ->groupBy('a.id')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->hydrateCounts($rows, User::class, 'user');
    }

    /**
     * Turns [{id, cnt}] rows into [{<key>: entity, count: int}], preserving order.
     *
     * DQL will not select a joined entity unless the root alias is selected too, so
     * the grouped queries return ids and the entities are fetched in one go here.
     *
     * @param list<array{id: int|string, cnt: int|string}> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function hydrateCounts(array $rows, string $class, string $key): array
    {
        if ([] === $rows) {
            return [];
        }

        $entities = [];
        foreach ($this->getEntityManager()->getRepository($class)->findBy(['id' => array_column($rows, 'id')]) as $entity) {
            $entities[$entity->getId()] = $entity;
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($entities[$id])) {
                $out[] = [$key => $entities[$id], 'count' => (int) $row['cnt']];
            }
        }

        return $out;
    }

    /**
     * Post counts for each of the 24 hours, indexed 0-23.
     *
     * Times are stored in UTC, so the buckets are built in UTC and then rotated by
     * the viewer's offset. Members either side of a daylight-saving change within the
     * window see their posts an hour out; grouping precisely would mean converting
     * every row individually, which is not worth it for a summary.
     *
     * @param list<int> $visibleForumIds
     *
     * @return array<int, int>
     */
    public function countByHourOfDay(
        array $visibleForumIds,
        ?User $author = null,
        ?\DateTimeImmutable $since = null,
        int $offsetMinutes = 0,
    ): array {
        // Raw SQL: HOUR() is not a DQL function, and registering one for a single
        // read-only histogram is more machinery than the query is worth.
        $sql = 'SELECT HOUR(p.created_at) AS h, COUNT(*) AS cnt
                  FROM posts p
                  JOIN threads t ON t.id = p.thread_id
                 WHERE t.forum_id IN (:forums)';

        $params = ['forums' => $visibleForumIds];
        $types = ['forums' => \Doctrine\DBAL\ArrayParameterType::INTEGER];

        if (null !== $author) {
            $sql .= ' AND p.author_id = :author';
            $params['author'] = $author->getId();
        }
        if (null !== $since) {
            $sql .= ' AND p.created_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }

        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery($sql.' GROUP BY h', $params, $types)
            ->fetchAllAssociative();

        $hours = array_fill(0, 24, 0);
        $shift = intdiv($offsetMinutes, 60);

        foreach ($rows as $row) {
            $hours[((int) $row['h'] + $shift + 24) % 24] += (int) $row['cnt'];
        }

        return $hours;
    }

    private function applyScope(QueryBuilder $qb, ?User $author, ?\DateTimeImmutable $since): void
    {
        if (null !== $author) {
            $qb->andWhere('p.author = :author')->setParameter('author', $author);
        }
        if (null !== $since) {
            $qb->andWhere('p.createdAt >= :since')->setParameter('since', $since);
        }
    }

    /** Joins needed by PostRenderer so a rendered post costs no extra queries. */
    private function baseRenderQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->leftJoin('a.rankSet', 'rs')->addSelect('rs')
            ->leftJoin('p.headerLayout', 'hl')->addSelect('hl')
            ->leftJoin('p.signatureLayout', 'sl')->addSelect('sl');
    }
}
