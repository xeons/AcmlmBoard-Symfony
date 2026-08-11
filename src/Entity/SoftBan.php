<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SoftBanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A temporary posting ban that leaves the account's power level intact, so a
 * moderator does not lose their badge for a week-long timeout. lib/function.php
 * folded a `softbans` hit into `$banned`.
 */
#[ORM\Entity(repositoryClass: SoftBanRepository::class)]
#[ORM\Table(name: 'soft_bans')]
#[ORM\Index(name: 'idx_soft_ban_expiry', columns: ['expires_at'])]
class SoftBan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Null means "until lifted by hand". */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $issuedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    public function __construct(User $user, ?\DateTimeImmutable $expiresAt = null, ?User $issuedBy = null)
    {
        $this->user = $user;
        $this->expiresAt = $expiresAt;
        $this->issuedBy = $issuedBy;
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
