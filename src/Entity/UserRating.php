<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRatingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** 0-10 rating one user gives another. One rating per pair; re-rating overwrites. */
#[ORM\Entity(repositoryClass: UserRatingRepository::class)]
#[ORM\Table(name: 'user_ratings')]
#[ORM\UniqueConstraint(name: 'uniq_user_rating', columns: ['rater_id', 'rated_id'])]
#[ORM\Index(name: 'idx_user_rating_rated', columns: ['rated_id'])]
class UserRating
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $rater;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $rated;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 10)]
    private int $rating = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $rater, User $rated, int $rating)
    {
        $this->rater = $rater;
        $this->rated = $rated;
        $this->setRating($rating);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRater(): User
    {
        return $this->rater;
    }

    public function getRated(): User
    {
        return $this->rated;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $rating): static
    {
        $this->rating = max(0, min(10, $rating));
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
