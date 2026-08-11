<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PollVoteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The unique constraint on (choice, user) is what the original relied on to stop
 * double-voting on a single choice, and it is preserved. The "one choice per poll"
 * rule for non-multi-vote polls is enforced in PollManager, backed by a second
 * unique index that is only meaningful because a null poll_id is impossible here.
 */
#[ORM\Entity(repositoryClass: PollVoteRepository::class)]
#[ORM\Table(name: 'poll_votes')]
#[ORM\UniqueConstraint(name: 'uniq_poll_vote_choice', columns: ['choice_id', 'user_id'])]
#[ORM\Index(name: 'idx_poll_vote_poll_user', columns: ['poll_id', 'user_id'])]
class PollVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Poll::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Poll $poll;

    #[ORM\ManyToOne(targetEntity: PollChoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PollChoice $choice;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Poll $poll, PollChoice $choice, User $user)
    {
        $this->poll = $poll;
        $this->choice = $choice;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPoll(): Poll
    {
        return $this->poll;
    }

    public function getChoice(): PollChoice
    {
        return $this->choice;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
