<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Forum;
use App\Entity\ForumRead;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumRead>
 */
class ForumReadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumRead::class);
    }

    /**
     * The user's whole read-state in one query, keyed by forum id - the index page
     * needs it for every row.
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function getReadMap(User $user): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.forum) AS fid, r.readAt')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['fid']] = $row['readAt'];
        }

        return $map;
    }

    public function markRead(User $user, Forum $forum, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();
        $existing = $this->findOneBy(['user' => $user, 'forum' => $forum]);

        if (null === $existing) {
            $this->getEntityManager()->persist(new ForumRead($user, $forum, $at));
        } else {
            $existing->setReadAt($at);
        }

        $this->getEntityManager()->flush();
    }

    /** Marks every forum read, replacing the original's DELETE-then-INSERT-SELECT pair. */
    public function markAllRead(User $user, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();
        $em = $this->getEntityManager();

        $existing = [];
        foreach ($this->findBy(['user' => $user]) as $row) {
            $existing[$row->getForum()->getId()] = $row;
            $row->setReadAt($at);
        }

        foreach ($em->getRepository(Forum::class)->findAll() as $forum) {
            if (!isset($existing[$forum->getId()])) {
                $em->persist(new ForumRead($user, $forum, $at));
            }
        }

        $em->flush();
    }
}
