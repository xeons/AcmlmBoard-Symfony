<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnnouncementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A null forum means a board-wide announcement (the original used forum id 0, which
 * collided with "no forum" everywhere it was read).
 */
#[ORM\Entity(repositoryClass: AnnouncementRepository::class)]
#[ORM\Table(name: 'announcements')]
#[ORM\Index(name: 'idx_announcement_forum', columns: ['forum_id', 'created_at'])]
class Announcement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: Forum::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Forum $forum = null;

    #[ORM\Column(length: 250)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 250)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\ManyToOne(targetEntity: PostLayout::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PostLayout $headerLayout = null;

    #[ORM\ManyToOne(targetEntity: PostLayout::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PostLayout $signatureLayout = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $tagValues = [];

    public function __construct(?User $author, string $title, string $body, ?Forum $forum = null)
    {
        $this->author = $author;
        $this->title = $title;
        $this->body = $body;
        $this->forum = $forum;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isGlobal(): bool
    {
        return null === $this->forum;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getForum(): ?Forum
    {
        return $this->forum;
    }

    public function setForum(?Forum $forum): static
    {
        $this->forum = $forum;

        return $this;
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

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function markEdited(): static
    {
        $this->editedAt = new \DateTimeImmutable();

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
}
