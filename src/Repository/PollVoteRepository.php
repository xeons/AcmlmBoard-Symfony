<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poll;
use App\Entity\PollVote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PollVote>
 */
class PollVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PollVote::class);
    }

    /**
     * Choice ids this user has already voted for in this poll. Used both to decide
     * whether more voting is allowed and to highlight their picks.
     *
     * @return list<int>
     */
    public function findChoiceIdsVotedBy(Poll $poll, ?User $user): array
    {
        if (null === $user) {
            return [];
        }

        return array_map('intval', $this->createQueryBuilder('v')
            ->select('IDENTITY(v.choice)')
            ->andWhere('v.poll = :poll')
            ->andWhere('v.user = :user')
            ->setParameter('poll', $poll)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function countVotesBy(Poll $poll, User $user): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.poll = :poll')
            ->andWhere('v.user = :user')
            ->setParameter('poll', $poll)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
