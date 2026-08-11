<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The original split every post across `posts` (metadata) and `posts_text` (body),
 * joined on `posts.id = posts_text.pid`, because MyISAM handled fixed-width rows
 * better. That split bought nothing but join noise, so it is one table here.
 */
#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
#[ORM\Index(name: 'idx_post_thread', columns: ['thread_id', 'id'])]
#[ORM\Index(name: 'idx_post_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_post_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_post_ip', columns: ['ip'])]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Thread::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Thread $thread;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Raw, as typed by the author. Sanitised on render, never on store. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: "You didn't enter anything in the post.")]
    #[Assert\Length(max: 65535, maxMessage: 'Posts are limited to {{ limit }} characters.')]
    private string $body = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /**
     * The author's post count at the moment this post was made - printed as "1234/5678"
     * in the post's user column. Not derivable after the fact, so it is stored.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $authorPostNumber = 0;

    /**
     * Snapshots of the author's header/signature, deduplicated across posts.
     * SignatureDisplay::AutoUpdating ignores these and reads the author's live layout.
     */
    #[ORM\ManyToOne(targetEntity: PostLayout::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PostLayout $headerLayout = null;

    #[ORM\ManyToOne(targetEntity: PostLayout::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PostLayout $signatureLayout = null;

    /**
     * Frozen values of the &numposts&-style tokens as they were when the post was
     * made, so an old post keeps saying "post #37" instead of silently updating.
     * Replaces the delimiter-packed `tagval` string.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $tagValues = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $editedBy = null;

    public function __construct(Thread $thread, ?User $author, string $body)
    {
        $this->thread = $thread;
        $this->author = $author;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isEditableBy(?User $user, bool $isForumModerator): bool
    {
        if (null === $user || $this->thread->isClosed()) {
            return false;
        }

        return $user === $this->author || $user->isModerator() || $isForumModerator;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getThread(): Thread
    {
        return $this->thread;
    }

    public function setThread(Thread $thread): static
    {
        $this->thread = $thread;

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

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

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

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getAuthorPostNumber(): int
    {
        return $this->authorPostNumber;
    }

    public function setAuthorPostNumber(int $number): static
    {
        $this->authorPostNumber = $number;

        return $this;
    }

    public function getHeaderLayout(): ?PostLayout
    {
        return $this->headerLayout;
    }

    public function setHeaderLayout(?PostLayout $layout): static
    {
        $this->headerLayout = $layout;

        return $this;
    }

    public function getSignatureLayout(): ?PostLayout
    {
        return $this->signatureLayout;
    }

    public function setSignatureLayout(?PostLayout $layout): static
    {
        $this->signatureLayout = $layout;

        return $this;
    }

    /** @return array<string, string> */
    public function getTagValues(): array
    {
        return $this->tagValues;
    }

    /** @param array<string, string> $tagValues */
    public function setTagValues(array $tagValues): static
    {
        $this->tagValues = $tagValues;

        return $this;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function getEditedBy(): ?User
    {
        return $this->editedBy;
    }

    public function markEdited(User $by, ?\DateTimeImmutable $at = null): static
    {
        $this->editedBy = $by;
        $this->editedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }
}
