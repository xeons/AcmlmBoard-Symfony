<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumBanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bars one user from posting in one forum until `expiresAt`. The original deleted
 * expired rows on every page load from lib/layout.php; expiry is evaluated at read
 * time here, and a scheduled command prunes the table.
 */
#[ORM\Entity(repositoryClass: ForumBanRepository::class)]
#[ORM\Table(name: 'forum_bans')]
#[ORM\UniqueConstraint(name: 'uniq_forum_ban', columns: ['forum_id', 'user_id'])]
#[ORM\Index(name: 'idx_forum_ban_expiry', columns: ['expires_at'])]
class ForumBan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Forum::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Forum $forum;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Null means permanent. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $issuedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    public function __construct(Forum $forum, User $user, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->forum = $forum;
        $this->user = $user;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return null === $this->expiresAt || $this->expiresAt > ($now ?? new \DateTimeImmutable());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getForum(): Forum
    {
        return $this->forum;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIssuedBy(): ?User
    {
        return $this->issuedBy;
    }

    public function setIssuedBy(?User $issuedBy): static
    {
        $this->issuedBy = $issuedBy;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }
}
