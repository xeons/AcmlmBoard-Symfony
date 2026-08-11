<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FavoriteRepository::class)]
#[ORM\Table(name: 'favorites')]
#[ORM\UniqueConstraint(name: 'uniq_favorite', columns: ['user_id', 'thread_id'])]
class Favorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'favorites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Thread::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Thread $thread;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Thread $thread)
    {
        $this->user = $user;
        $this->thread = $thread;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getThread(): Thread
    {
        return $this->thread;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
