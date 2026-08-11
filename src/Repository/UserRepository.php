<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Forum;
use App\Entity\User;
use App\Enum\PowerLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @implements PasswordUpgraderInterface<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Rehashes a password to the current algorithm after a successful login. This is
     * what retires a legacy md5 hash without ever asking the user to reset it.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $user->setPasswordLegacyMd5(false);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['usernameCanonical' => User::canonicalizeUsername($username)]);
    }

    public function usernameExists(string $username): bool
    {
        return null !== $this->findOneByUsername($username);
    }

    public function emailInUse(string $email): bool
    {
        return (bool) $this->createQueryBuilder('u')
            ->select('1')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower($email))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Users active within the window. `lastPostAt` is checked too because the
     * original counted someone who had just posted as online even if their
     * activity timestamp had not been written yet.
     *
     * @return list<User>
     */
    public function findOnline(\DateTimeImmutable $since, ?Forum $forum = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.lastActivityAt > :since OR u.lastPostAt > :since')
            ->setParameter('since', $since)
            ->orderBy('u.username', 'ASC');

        if (null !== $forum) {
            $qb->andWhere('u.currentForum = :forum')->setParameter('forum', $forum);
        }

        return $qb->getQuery()->getResult();
    }

    public function countOnline(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.lastActivityAt > :since OR u.lastPostAt > :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Members whose birthday falls on the given month/day, in any year.
     *
     * @return list<User>
     */
    public function findBirthdays(int $month, int $day): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.birthdayMonthDay = :md')
            ->setParameter('md', $month * 100 + $day)
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everyone whose birthday falls in a given month, for the calendar.
     *
     * @return list<User>
     */
    public function findBirthdaysInMonth(int $month): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.birthdayMonthDay >= :from')
            ->andWhere('u.birthdayMonthDay < :to')
            ->setParameter('from', $month * 100)
            ->setParameter('to', ($month + 1) * 100)
            ->orderBy('u.birthdayMonthDay', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findNewest(): ?User
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Position in the post-count league table, 1-based. Ties share a position, which
     * is what `COUNT(*) WHERE posts > n` + 1 produced originally.
     */
    public function getPostRank(User $user): int
    {
        return 1 + (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.posts > :posts')
            ->setParameter('posts', $user->getPosts())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** How many posts ahead of the user the next-ranked member is; null at the top. */
    public function getPostsToNextRank(User $user): ?int
    {
        $next = $this->createQueryBuilder('u')
            ->select('MIN(u.posts)')
            ->andWhere('u.posts > :posts')
            ->setParameter('posts', $user->getPosts())
            ->getQuery()
            ->getSingleScalarResult();

        return null === $next ? null : (int) $next - $user->getPosts();
    }

    /** Members with at least this many posts, ordered high to low. Drives percentile ranks. */
    public function findPostCountsAbove(int $minPosts): array
    {
        return $this->createQueryBuilder('u')
            ->select('u.posts')
            ->andWhere('u.posts >= :min')
            ->andWhere('u.powerLevel >= :member')
            ->setParameter('min', $minPosts)
            ->setParameter('member', PowerLevel::Member)
            ->orderBy('u.posts', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /** Base query for the member list, with sorting handled by the caller. */
    public function memberListQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.rankSet', 'rs')->addSelect('rs');
    }

    /**
     * Members in a given post band who have been seen recently, for the ranks page.
     *
     * @return list<User>
     */
    public function findActiveInPostRange(int $minPosts, int $maxPosts, \DateTimeImmutable $activeSince, ?int $rankSetId): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.posts >= :min')
            ->andWhere('u.posts < :max')
            ->andWhere('u.lastActivityAt > :since OR u.lastPostAt > :since')
            ->setParameter('min', $minPosts)
            ->setParameter('max', $maxPosts)
            ->setParameter('since', $activeSince)
            ->orderBy('u.username', 'ASC');

        if (null !== $rankSetId) {
            $qb->andWhere('IDENTITY(u.rankSet) = :rankSet')->setParameter('rankSet', $rankSetId);
        }

        return $qb->getQuery()->getResult();
    }

    public function countInPostRange(int $minPosts, int $maxPosts, ?int $rankSetId): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.posts >= :min')
            ->andWhere('u.posts < :max')
            ->setParameter('min', $minPosts)
            ->setParameter('max', $maxPosts);

        if (null !== $rankSetId) {
            $qb->andWhere('IDENTITY(u.rankSet) = :rankSet')->setParameter('rankSet', $rankSetId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Accounts that have used an IP, for the admin IP search.
     *
     * @return list<User>
     */
    public function findByLastIp(string $ip): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.lastIp = :ip')
            ->setParameter('ip', $ip)
            ->orderBy('u.lastActivityAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<User> */
    public function searchByUsername(string $fragment, int $limit = 20): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.usernameCanonical LIKE :q')
            ->setParameter('q', '%'.User::canonicalizeUsername($fragment).'%')
            ->orderBy('u.username', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
