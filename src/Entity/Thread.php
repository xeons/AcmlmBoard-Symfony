<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThreadRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ThreadRepository::class)]
#[ORM\Table(name: 'threads')]
#[ORM\Index(name: 'idx_thread_forum_sort', columns: ['forum_id', 'sticky', 'last_post_at'])]
#[ORM\Index(name: 'idx_thread_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_thread_last_post', columns: ['last_post_at'])]
class Thread
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Please enter a thread title.')]
    #[Assert\Length(max: 100)]
    private string $title = '';

    #[ORM\ManyToOne(targetEntity: Forum::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Forum $forum;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Relative path of the post icon under public/images/icons, or null. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $views = 0;

    /**
     * Reply count, i.e. total posts minus one. Kept denormalised because the forum
     * listing and the pager both need it on every row.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $replies = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastPostAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lastPoster = null;

    /** No further replies accepted. Set by moderators or by "close after posting". */
    #[ORM\Column(options: ['default' => false])]
    private bool $closed = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $sticky = false;

    /**
     * Admin-only lock that also prevents local and full moderators from editing the
     * thread. Gated behind the `lockable` board setting, as in the original.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $locked = false;

    #[ORM\OneToOne(targetEntity: Poll::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Poll $poll = null;

    public function __construct(Forum $forum, ?User $author, string $title)
    {
        $this->forum = $forum;
        $this->author = $author;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastPostAt = $this->createdAt;
        $this->lastPoster = $author;
    }

    /** Total posts in the thread, i.e. the opening post plus replies. */
    public function getPostCount(): int
    {
        return $this->replies + 1;
    }

    public function pageCount(int $perPage): int
    {
        return max(1, (int) ceil($this->getPostCount() / max(1, $perPage)));
    }

    /** True when the thread has enough replies to earn the "hot" icon. */
    public function isHot(int $threshold): bool
    {
        return $threshold > 0 && $this->replies >= $threshold;
    }

    public function isEditableBy(?User $user, bool $lockableEnabled): bool
    {
        if (null === $user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if ($this->locked && $lockableEnabled) {
            return false;
        }

        return $user->isModerator() || $this->forum->isModeratedBy($user);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getForum(): Forum
    {
        return $this->forum;
    }

    public function setForum(Forum $forum): static
    {
        $this->forum = $forum;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function setViews(int $views): static
    {
        $this->views = $views;

        return $this;
    }

    public function getReplies(): int
    {
        return $this->replies;
    }

    public function setReplies(int $replies): static
    {
        $this->replies = max(0, $replies);

        return $this;
    }

    public function incrementReplies(int $by = 1): static
    {
        $this->replies = max(0, $this->replies + $by);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getLastPostAt(): \DateTimeImmutable
    {
        return $this->lastPostAt;
    }

    public function setLastPostAt(\DateTimeImmutable $at): static
    {
        $this->lastPostAt = $at;

        return $this;
    }

    public function getLastPoster(): ?User
    {
        return $this->lastPoster;
    }

    public function setLastPoster(?User $lastPoster): static
    {
        $this->lastPoster = $lastPoster;

        return $this;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function setClosed(bool $closed): static
    {
        $this->closed = $closed;

        return $this;
    }

    public function isSticky(): bool
    {
        return $this->sticky;
    }

    public function setSticky(bool $sticky): static
    {
        $this->sticky = $sticky;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }

    public function getPoll(): ?Poll
    {
        return $this->poll;
    }

    public function setPoll(?Poll $poll): static
    {
        $this->poll = $poll;

        return $this;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
