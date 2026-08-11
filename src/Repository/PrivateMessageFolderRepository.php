<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PrivateMessageFolder;
use App\Entity\User;
use App\Enum\MessageFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivateMessageFolder>
 */
class PrivateMessageFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivateMessageFolder::class);
    }

    /** @return list<PrivateMessageFolder> */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByNumber(User $user, int $number): ?PrivateMessageFolder
    {
        return $this->findOneBy(['user' => $user, 'number' => $number]);
    }

    /** Next free folder number for this user, starting at the reserved boundary. */
    public function nextNumberFor(User $user): int
    {
        $max = $this->createQueryBuilder('f')
            ->select('MAX(f.number)')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max
            ? MessageFolder::FIRST_USER_FOLDER
            : max(MessageFolder::FIRST_USER_FOLDER, (int) $max + 1);
    }
}
