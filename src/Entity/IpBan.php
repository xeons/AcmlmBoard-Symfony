<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IpBanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * IP and CIDR bans.
 *
 * The original matched with `WHERE INSTR('$userip', ip)=1`, i.e. prefix matching on
 * the string - so a ban on "1.2.3" also caught 1.2.30.0, and a ban on "1" caught a
 * quarter of the internet. Worse, `$userip` was interpolated unescaped, making the
 * ban table itself an injection point. Bans are stored as explicit CIDR ranges here
 * and matched with Symfony's IpUtils, which understands IPv4 and IPv6.
 */
#[ORM\Entity(repositoryClass: IpBanRepository::class)]
#[ORM\Table(name: 'ip_bans')]
#[ORM\UniqueConstraint(name: 'uniq_ip_ban_range', columns: ['ip_range'])]
class IpBan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** A single address ("203.0.113.7") or a CIDR block ("203.0.113.0/24", "2001:db8::/32"). */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '~^(?:\d{1,3}(?:\.\d{1,3}){3}(?:/\d{1,2})?|[0-9A-Fa-f:]+(?:/\d{1,3})?)$~',
        message: 'Enter an IP address or a CIDR range, e.g. 203.0.113.0/24.',
    )]
    private string $ipRange = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $reason = null;

    /** Null means permanent; the original's `perm` flag plus a date is one field here. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $issuedBy = null;

    public function __construct(string $ipRange, ?User $issuedBy = null)
    {
        $this->ipRange = $ipRange;
        $this->issuedBy = $issuedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return null === $this->expiresAt || $this->expiresAt > ($now ?? new \DateTimeImmutable());
    }

    public function isPermanent(): bool
    {
        return null === $this->expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIpRange(): string
    {
        return $this->ipRange;
    }

    public function setIpRange(string $ipRange): static
    {
        $this->ipRange = $ipRange;

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
}
