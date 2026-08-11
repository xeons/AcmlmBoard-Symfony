<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuestSessionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A logged-out visitor, for the guest counter on the index and forum pages.
 *
 * The original ran `DELETE FROM guests WHERE ip=... OR date<...` followed by an
 * INSERT on every single request from every guest - two writes per pageview, with no
 * unique key, so concurrent requests from one address happily created duplicates.
 * The IP is the primary key here and the write is a single upsert.
 */
#[ORM\Entity(repositoryClass: GuestSessionRepository::class)]
#[ORM\Table(name: 'guest_sessions')]
#[ORM\Index(name: 'idx_guest_seen', columns: ['last_seen_at'])]
#[ORM\Index(name: 'idx_guest_forum', columns: ['current_forum_id'])]
class GuestSession
{
    #[ORM\Id]
    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastUrl = null;

    #[ORM\ManyToOne(targetEntity: Forum::class)]
    #[ORM\JoinColumn(name: 'current_forum_id', nullable: true, onDelete: 'SET NULL')]
    private ?Forum $currentForum = null;

    public function __construct(string $ip)
    {
        $this->ip = $ip;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(?\DateTimeImmutable $at = null): static
    {
        $this->lastSeenAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function getLastUrl(): ?string
    {
        return $this->lastUrl;
    }

    public function setLastUrl(?string $url): static
    {
        $this->lastUrl = null === $url ? null : mb_substr($url, 0, 255);

        return $this;
    }

    public function getCurrentForum(): ?Forum
    {
        return $this->currentForum;
    }

    public function setCurrentForum(?Forum $forum): static
    {
        $this->currentForum = $forum;

        return $this;
    }
}
