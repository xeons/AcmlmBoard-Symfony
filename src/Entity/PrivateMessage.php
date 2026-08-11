<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrivateMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Private and system messages. Like posts, the original split these across `pmsgs`
 * and `pmsgs_text`; merged here.
 *
 * Sender and recipient each keep an independent folder number, so a message the
 * sender deletes stays in the recipient's inbox. Folder 0 is "deleted"; the row is
 * retained rather than removed, which is what the original's UPDATE-to-0 did.
 */
#[ORM\Entity(repositoryClass: PrivateMessageRepository::class)]
#[ORM\Table(name: 'private_messages')]
#[ORM\Index(name: 'idx_pm_recipient', columns: ['recipient_id', 'recipient_folder', 'id'])]
#[ORM\Index(name: 'idx_pm_sender', columns: ['sender_id', 'sender_folder', 'id'])]
#[ORM\Index(name: 'idx_pm_unread', columns: ['recipient_id', 'read_at'])]
class PrivateMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $sender = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Please give the message a title.')]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: "You didn't enter a message.")]
    #[Assert\Length(max: 65535)]
    private string $body = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $recipientFolder = 1;

    #[ORM\Column(options: ['default' => 2])]
    private int $senderFolder = 2;

    /**
     * A system message blocks the recipient from posting until it is read - the rule
     * newreply.php enforced by counting unread messages from `$bconf[sys_id]`.
     * Making it an explicit flag means it no longer depends on which account happens
     * to be configured as the system user.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $system = false;

    #[ORM\ManyToOne(targetEntity: PostLayout::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PostLayout $headerLayout = null;

    #[ORM\ManyToOne(targetEntity: PostLayout::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PostLayout $signatureLayout = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $tagValues = [];

    public function __construct(?User $sender, User $recipient, string $title, string $body)
    {
        $this->sender = $sender;
        $this->recipient = $recipient;
        $this->title = $title;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isUnread(): bool
    {
        return null === $this->readAt;
    }

    public function markRead(): static
    {
        $this->readAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function markUnread(): static
    {
        $this->readAt = null;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
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

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getRecipientFolder(): int
    {
        return $this->recipientFolder;
    }

    public function setRecipientFolder(int $folder): static
    {
        $this->recipientFolder = $folder;

        return $this;
    }

    public function getSenderFolder(): int
    {
        return $this->senderFolder;
    }

    public function setSenderFolder(int $folder): static
    {
        $this->senderFolder = $folder;

        return $this;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function setSystem(bool $system): static
    {
        $this->system = $system;

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
