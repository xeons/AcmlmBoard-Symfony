<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Punishment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Punishment>
 */
class PunishmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Punishment::class);
    }

    public function findForUser(User $user): ?Punishment
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Everyone with a disciplinary record, for the staff tracker.
     *
     * @return list<Punishment>
     */
    public function findAllWithUsers(): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')->addSelect('u')
            ->leftJoin('p.thread', 't')->addSelect('t')
            ->orderBy('p.strikes', 'DESC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Records for a set of users in one query, so the thread page can annotate every
     * post without a query per post - which is what the original did, inside the
     * render loop, and it also created rows as a side effect.
     *
     * @param list<User> $users
     *
     * @return array<int, Punishment> keyed by user id
     */
    public function findForUsers(array $users): array
    {
        if ([] === $users) {
            return [];
        }

        $records = $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')->addSelect('u')
            ->andWhere('p.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($records as $record) {
            $map[$record->getUser()->getId()] = $record;
        }

        return $map;
    }
}
