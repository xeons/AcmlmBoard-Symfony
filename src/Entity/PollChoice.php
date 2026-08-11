<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'poll_choices')]
#[ORM\Index(name: 'idx_poll_choice_poll', columns: ['poll_id'])]
class PollChoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Poll::class, inversedBy: 'choices')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Poll $poll = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'A poll choice cannot be empty.')]
    #[Assert\Length(max: 255)]
    private string $label = '';

    /**
     * Bar colour. Constrained to a hex triplet because the original interpolated
     * this straight into `bgcolor='$pollc[color]'` with no escaping at all.
     */
    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: '/^#[0-9A-Fa-f]{6}$/', message: 'Pick a colour like #33CCFF.')]
    private ?string $color = null;

    /**
     * Denormalised tally. PollVote rows remain the source of truth; this is
     * maintained inside the same transaction as the vote and lets the thread page
     * render a poll without an aggregate query per choice.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $voteCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function percentageOf(int $totalVotes): float
    {
        return $totalVotes > 0 ? round($this->voteCount / $totalVotes * 100, 1) : 0.0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPoll(): ?Poll
    {
        return $this->poll;
    }

    public function setPoll(?Poll $poll): static
    {
        $this->poll = $poll;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getVoteCount(): int
    {
        return $this->voteCount;
    }

    public function setVoteCount(int $voteCount): static
    {
        $this->voteCount = max(0, $voteCount);

        return $this;
    }

    public function incrementVoteCount(): static
    {
        ++$this->voteCount;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
