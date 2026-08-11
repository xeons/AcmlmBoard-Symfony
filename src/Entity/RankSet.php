<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RankSetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named ladder of post-count ranks that a user can pick for their own posts.
 *
 * One set in the original was special: rank set 3 ("Global") had its thresholds
 * rewritten on *every page load* by updategb(), which walked the top 70% of all
 * users with >=1000 posts and issued up to nine UPDATEs matching rank text with
 * `LIKE '%=3%'`. That is a percentile ladder, so it is flagged as one and
 * recalculated by a scheduled command instead.
 */
#[ORM\Entity(repositoryClass: RankSetRepository::class)]
#[ORM\Table(name: 'rank_sets')]
class RankSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Thresholds are percentiles of the active population, recomputed periodically. */
    #[ORM\Column(options: ['default' => false])]
    private bool $percentileBased = false;

    /** @var Collection<int, Rank> */
    #[ORM\OneToMany(targetEntity: Rank::class, mappedBy: 'rankSet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['minPosts' => 'ASC'])]
    private Collection $ranks;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->ranks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function isPercentileBased(): bool
    {
        return $this->percentileBased;
    }

    public function setPercentileBased(bool $percentileBased): static
    {
        $this->percentileBased = $percentileBased;

        return $this;
    }

    /** @return Collection<int, Rank> */
    public function getRanks(): Collection
    {
        return $this->ranks;
    }

    public function addRank(Rank $rank): static
    {
        if (!$this->ranks->contains($rank)) {
            $this->ranks->add($rank);
            $rank->setRankSet($this);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
