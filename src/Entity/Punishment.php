<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PunishmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The staff disciplinary record for one user, backing the punishment tracker in
 * /ppanel. Each record owns a hidden staff-only thread used for comments.
 *
 * thread.php created these rows lazily while *rendering a page*, inside the post
 * loop, for every post by every user a staff member happened to look at - and did
 * so with an UPDATE whose WHERE clause pointed at the wrong table. Creation is now
 * explicit and happens in PunishmentManager.
 */
#[ORM\Entity(repositoryClass: PunishmentRepository::class)]
#[ORM\Table(name: 'punishments')]
#[ORM\UniqueConstraint(name: 'uniq_punishment_user', columns: ['user_id'])]
class Punishment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Staff-only discussion thread, created in the configured panel forum. */
    #[ORM\OneToOne(targetEntity: Thread::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Thread $thread = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $strikes = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user)
    {
        $this->user = $user;
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

    public function getThread(): ?Thread
    {
        return $this->thread;
    }

    public function setThread(?Thread $thread): static
    {
        $this->thread = $thread;

        return $this;
    }

    public function getStrikes(): int
    {
        return $this->strikes;
    }

    public function setStrikes(int $strikes): static
    {
        $this->strikes = max(0, $strikes);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function addStrike(): static
    {
        return $this->setStrikes($this->strikes + 1);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
