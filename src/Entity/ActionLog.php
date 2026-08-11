<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActionLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Moderator audit trail. The original wrote free-text sentences ("User 12 trashed
 * thread 34") that could not be queried; the actor, action and target are separate
 * columns here, with the human sentence rendered at display time.
 */
#[ORM\Entity(repositoryClass: ActionLogRepository::class)]
#[ORM\Table(name: 'action_log')]
#[ORM\Index(name: 'idx_action_log_actor', columns: ['actor_id'])]
#[ORM\Index(name: 'idx_action_log_created', columns: ['created_at'])]
class ActionLog
{
    public const ACTION_THREAD_EDIT = 'thread.edit';
    public const ACTION_THREAD_STICKY = 'thread.sticky';
    public const ACTION_THREAD_CLOSE = 'thread.close';
    public const ACTION_THREAD_LOCK = 'thread.lock';
    public const ACTION_THREAD_TRASH = 'thread.trash';
    public const ACTION_THREAD_DELETE = 'thread.delete';
    public const ACTION_THREAD_MOVE = 'thread.move';
    public const ACTION_POST_EDIT = 'post.edit';
    public const ACTION_POST_DELETE = 'post.delete';
    public const ACTION_USER_EDIT = 'user.edit';
    public const ACTION_USER_BAN = 'user.ban';
    public const ACTION_USER_SOFTBAN = 'user.softban';
    public const ACTION_USER_FORUMBAN = 'user.forumban';
    public const ACTION_IP_BAN = 'ip.ban';
    public const ACTION_CONFIG_CHANGE = 'config.change';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 64)]
    private string $action;

    /** Free-form target reference, e.g. "thread:1234". */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $target = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $context = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $context */
    public function __construct(?User $actor, string $action, ?string $target = null, array $context = [], ?string $ip = null)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->target = $target;
        $this->context = $context;
        $this->ip = $ip;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
