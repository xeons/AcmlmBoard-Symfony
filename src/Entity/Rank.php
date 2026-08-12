<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RankRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** One rung of a RankSet: "at N posts you are called X". */
#[ORM\Entity(repositoryClass: RankRepository::class)]
#[ORM\Table(name: 'ranks')]
#[ORM\Index(name: 'idx_rank_lookup', columns: ['rank_set_id', 'min_posts'])]
class Rank
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RankSet::class, inversedBy: 'ranks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?RankSet $rankSet = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $minPosts = 0;

    /**
     * Nearly always a sprite stacked over a name, as in
     * "<img src=images/ranks/goomba.gif width=16 height=16><br>Goomba". Rendered
     * through app.rank_sanitizer, which permits <img> with a relative src.
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $label = '';

    /**
     * For percentile-based sets: the share of the ranked population at or above this
     * rung, e.g. 0.01 for the top 1%. Null for fixed post-count sets.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Assert\Range(min: 0, max: 1)]
    private ?float $percentile = null;

    public function __construct(?RankSet $rankSet = null, int $minPosts = 0, string $label = '')
    {
        $this->rankSet = $rankSet;
        $this->minPosts = $minPosts;
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRankSet(): ?RankSet
    {
        return $this->rankSet;
    }

    public function setRankSet(?RankSet $rankSet): static
    {
        $this->rankSet = $rankSet;

        return $this;
    }

    public function getMinPosts(): int
    {
        return $this->minPosts;
    }

    public function setMinPosts(int $minPosts): static
    {
        $this->minPosts = $minPosts;

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

    public function getPercentile(): ?float
    {
        return $this->percentile;
    }

    public function setPercentile(?float $percentile): static
    {
        $this->percentile = $percentile;

        return $this;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
