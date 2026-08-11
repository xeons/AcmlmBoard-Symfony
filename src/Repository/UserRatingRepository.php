<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserRating;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserRating>
 */
class UserRatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserRating::class);
    }

    /** @return array{count: int, average: float} */
    public function getSummaryFor(User $user): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS num, AVG(r.rating) AS avg')
            ->andWhere('r.rated = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) $row['num'],
            'average' => round((float) $row['avg'], 2),
        ];
    }

    public function findByPair(User $rater, User $rated): ?UserRating
    {
        return $this->findOneBy(['rater' => $rater, 'rated' => $rated]);
    }

    /** @return list<UserRating> */
    public function findReceivedBy(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.rater', 'u')->addSelect('u')
            ->andWhere('r.rated = :user')
            ->setParameter('user', $user)
            ->orderBy('r.rating', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<UserRating> */
    public function findGivenBy(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.rated', 'u')->addSelect('u')
            ->andWhere('r.rater = :user')
            ->setParameter('user', $user)
            ->orderBy('r.rating', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
