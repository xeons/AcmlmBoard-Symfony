<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Enum\MessageFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivateMessage>
 */
class PrivateMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivateMessage::class);
    }

    /**
     * One page of a folder. `Sent` reads the sender side of the row, every other
     * folder reads the recipient side.
     *
     * @return Paginator<PrivateMessage>
     */
    public function paginateFolder(User $user, int $folder, int $page, int $perPage, bool $system = false): Paginator
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.sender', 's')->addSelect('s')
            ->leftJoin('m.recipient', 'r')->addSelect('r')
            ->andWhere('m.system = :system')
            ->setParameter('system', $system)
            ->orderBy('m.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (MessageFolder::Sent->value === $folder) {
            $qb->andWhere('m.sender = :user')->andWhere('m.senderFolder = :folder');
        } else {
            $qb->andWhere('m.recipient = :user')->andWhere('m.recipientFolder = :folder');
        }

        $qb->setParameter('user', $user)->setParameter('folder', $folder);

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    public function countInFolder(User $user, int $folder, bool $system = false): int
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.system = :system')
            ->setParameter('system', $system);

        if (MessageFolder::Sent->value === $folder) {
            $qb->andWhere('m.sender = :user')->andWhere('m.senderFolder = :folder');
        } else {
            $qb->andWhere('m.recipient = :user')->andWhere('m.recipientFolder = :folder');
        }

        return (int) $qb->setParameter('user', $user)->setParameter('folder', $folder)
            ->getQuery()->getSingleScalarResult();
    }

    /** Total messages addressed to the user, excluding deleted. */
    public function countReceived(User $user, bool $system = false): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.recipient = :user')
            ->andWhere('m.recipientFolder != :deleted')
            ->andWhere('m.system = :system')
            ->setParameter('user', $user)
            ->setParameter('deleted', MessageFolder::Deleted->value)
            ->setParameter('system', $system)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnread(User $user, bool $system = false): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.recipient = :user')
            ->andWhere('m.recipientFolder != :deleted')
            ->andWhere('m.readAt IS NULL')
            ->andWhere('m.system = :system')
            ->setParameter('user', $user)
            ->setParameter('deleted', MessageFolder::Deleted->value)
            ->setParameter('system', $system)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether the user has unread system messages, which blocks posting.
     * A single EXISTS-style query rather than a full count.
     */
    public function hasUnreadSystemMessages(User $user): bool
    {
        return null !== $this->createQueryBuilder('m')
            ->select('m.id')
            ->andWhere('m.recipient = :user')
            ->andWhere('m.recipientFolder != :deleted')
            ->andWhere('m.readAt IS NULL')
            ->andWhere('m.system = true')
            ->setParameter('user', $user)
            ->setParameter('deleted', MessageFolder::Deleted->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestReceived(User $user, bool $system = false): ?PrivateMessage
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.sender', 's')->addSelect('s')
            ->andWhere('m.recipient = :user')
            ->andWhere('m.recipientFolder != :deleted')
            ->andWhere('m.system = :system')
            ->setParameter('user', $user)
            ->setParameter('deleted', MessageFolder::Deleted->value)
            ->setParameter('system', $system)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Loads the given ids, but only those the user is actually allowed to act on.
     * The original trusted the id list straight from the form and only filtered
     * afterwards, in PHP.
     *
     * @param list<int> $ids
     *
     * @return list<PrivateMessage>
     */
    public function findOwnedByIds(User $user, array $ids, bool $sentSide): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('ids', $ids);

        if ($sentSide) {
            $qb->andWhere('m.sender = :user');
        } else {
            $qb->andWhere('m.recipient = :user');
        }

        return $qb->setParameter('user', $user)->getQuery()->getResult();
    }

    public function countSentSince(User $user, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.sender = :user')
            ->andWhere('m.createdAt > :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
