<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PollRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PollRepository::class)]
#[ORM\Table(name: 'polls')]
class Poll
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'A poll needs a question.')]
    #[Assert\Length(max: 255)]
    private string $question = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $briefing = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $closed = false;

    /** `poll.doublevote`: a voter may pick more than one choice, but each only once. */
    #[ORM\Column(options: ['default' => false])]
    private bool $multiVote = false;

    /** @var Collection<int, PollChoice> */
    #[ORM\OneToMany(targetEntity: PollChoice::class, mappedBy: 'poll', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Count(min: 2, minMessage: 'A poll needs at least {{ limit }} choices.')]
    #[Assert\Valid]
    private Collection $choices;

    public function __construct()
    {
        $this->choices = new ArrayCollection();
    }

    public function getTotalVotes(): int
    {
        $total = 0;
        foreach ($this->choices as $choice) {
            $total += $choice->getVoteCount();
        }

        return $total;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;

        return $this;
    }

    public function getBriefing(): ?string
    {
        return $this->briefing;
    }

    public function setBriefing(?string $briefing): static
    {
        $this->briefing = $briefing;

        return $this;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function setClosed(bool $closed): static
    {
        $this->closed = $closed;

        return $this;
    }

    public function isMultiVote(): bool
    {
        return $this->multiVote;
    }

    public function setMultiVote(bool $multiVote): static
    {
        $this->multiVote = $multiVote;

        return $this;
    }

    /** @return Collection<int, PollChoice> */
    public function getChoices(): Collection
    {
        return $this->choices;
    }

    public function addChoice(PollChoice $choice): static
    {
        if (!$this->choices->contains($choice)) {
            $this->choices->add($choice);
            $choice->setPoll($this);
        }

        return $this;
    }

    public function removeChoice(PollChoice $choice): static
    {
        if ($this->choices->removeElement($choice) && $choice->getPoll() === $this) {
            $choice->setPoll(null);
        }

        return $this;
    }
}
