<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Local moderation grant. A user listed here moderates that one forum regardless of
 * their global power level - thread.php set `$ismod=1` on a hit in `forummods`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forum_moderators')]
#[ORM\UniqueConstraint(name: 'uniq_forum_moderator', columns: ['forum_id', 'user_id'])]
class ForumModerator
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Forum::class, inversedBy: 'moderators')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Forum $forum;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'moderatedForums')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    public function __construct(Forum $forum, User $user)
    {
        $this->forum = $forum;
        $this->user = $user;
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
}
