<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Poll;
use App\Entity\PollVote;
use App\Entity\User;
use App\Repository\PollVoteRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vote validation and recording.
 */
final class PollManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PollVoteRepository $votes,
    ) {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function vote(Poll $poll, User $voter, int $choiceId): array
    {
        if ($poll->isClosed()) {
            return ['ok' => false, 'message' => 'This poll is closed.'];
        }

        if ($voter->isBanned()) {
            return ['ok' => false, 'message' => 'Banned members cannot vote.'];
        }

        // The choice must belong to *this* poll. The original took the id straight
        // from the URL and inserted it, so a vote could be recorded against any
        // choice on the board.
        $choice = null;
        foreach ($poll->getChoices() as $candidate) {
            if ($candidate->getId() === $choiceId) {
                $choice = $candidate;
                break;
            }
        }

        if (null === $choice) {
            return ['ok' => false, 'message' => 'That is not a valid choice for this poll.'];
        }

        $alreadyVoted = $this->votes->findChoiceIdsVotedBy($poll, $voter);

        if (\in_array($choiceId, $alreadyVoted, true)) {
            return ['ok' => false, 'message' => 'You have already voted for that choice.'];
        }

        if (!$poll->isMultiVote() && [] !== $alreadyVoted) {
            return ['ok' => false, 'message' => 'You have already voted in this poll.'];
        }

        try {
            $this->em->wrapInTransaction(function () use ($poll, $choice, $voter): void {
                $this->em->persist(new PollVote($poll, $choice, $voter));
                $choice->incrementVoteCount();
                $this->em->flush();
            });
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submissions of the same choice; the index settled it.
            return ['ok' => false, 'message' => 'You have already voted for that choice.'];
        }

        return ['ok' => true, 'message' => 'Your vote has been recorded.'];
    }

    /** Rebuilds the denormalised tallies from the vote rows. */
    public function recount(Poll $poll): void
    {
        foreach ($poll->getChoices() as $choice) {
            $count = (int) $this->votes->createQueryBuilder('v')
                ->select('COUNT(v.id)')
                ->andWhere('v.choice = :choice')
                ->setParameter('choice', $choice)
                ->getQuery()
                ->getSingleScalarResult();

            $choice->setVoteCount($count);
        }

        $this->em->flush();
    }
}
