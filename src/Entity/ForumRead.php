<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumReadRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Mark forum read" bookmark. A thread shows the new-post icon when its last post is
 * newer than this timestamp. Guests have no row and fall back to a one-hour window,
 * which is what the original did with `lastpostdate>ctime()-3600`.
 */
#[ORM\Entity(repositoryClass: ForumReadRepository::class)]
#[ORM\Table(name: 'forum_reads')]
#[ORM\UniqueConstraint(name: 'uniq_forum_read', columns: ['user_id', 'forum_id'])]
class ForumRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Forum::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Forum $forum;

    #[ORM\Column]
    private \DateTimeImmutable $readAt;

    public function __construct(User $user, Forum $forum, ?\DateTimeImmutable $readAt = null)
    {
        $this->user = $user;
        $this->forum = $forum;
        $this->readAt = $readAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getForum(): Forum
    {
        return $this->forum;
    }

    public function getReadAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(\DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;

        return $this;
    }
}
