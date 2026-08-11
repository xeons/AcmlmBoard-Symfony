<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ForumRepository::class)]
#[ORM\Table(name: 'forums')]
#[ORM\Index(name: 'idx_forum_category', columns: ['category_id'])]
class Forum
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 250)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 250)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'forums')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Category $category = null;

    /**
     * Three independent thresholds, exactly as in the original `forums` table:
     * minPower gates reading, minPowerThread gates starting threads, and
     * minPowerReply gates replying. A value <= 0 means "no restriction" - the old
     * SQL was `WHERE minpower<=$power OR minpower<=0`, so 0 was never a real gate.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $minPower = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $minPowerThread = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $minPowerReply = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $threadCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $postCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPostAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lastPoster = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /**
     * Threads moved here by the "Trash" moderator action. The original hardcoded
     * `$trashid=20` in thread.php; it is a flag on the forum now.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $trash = false;

    /** @var Collection<int, ForumModerator> */
    #[ORM\OneToMany(targetEntity: ForumModerator::class, mappedBy: 'forum', orphanRemoval: true)]
    private Collection $moderators;

    public function __construct()
    {
        $this->moderators = new ArrayCollection();
    }

    /** @return list<User> */
    public function getModeratorUsers(): array
    {
        return array_map(
            static fn (ForumModerator $m): User => $m->getUser(),
            $this->moderators->toArray(),
        );
    }

    public function isModeratedBy(?User $user): bool
    {
        if (null === $user) {
            return false;
        }

        foreach ($this->moderators as $moderator) {
            if ($moderator->getUser() === $user) {
                return true;
            }
        }

        return false;
    }

    public function isReadableBy(int $power): bool
    {
        return $this->minPower <= 0 || $power >= $this->minPower;
    }

    public function isRestricted(): bool
    {
        return $this->minPower > 0;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getMinPower(): int
    {
        return $this->minPower;
    }

    public function setMinPower(int $minPower): static
    {
        $this->minPower = $minPower;

        return $this;
    }

    public function getMinPowerThread(): int
    {
        return $this->minPowerThread;
    }

    public function setMinPowerThread(int $minPowerThread): static
    {
        $this->minPowerThread = $minPowerThread;

        return $this;
    }

    public function getMinPowerReply(): int
    {
        return $this->minPowerReply;
    }

    public function setMinPowerReply(int $minPowerReply): static
    {
        $this->minPowerReply = $minPowerReply;

        return $this;
    }

    public function getThreadCount(): int
    {
        return $this->threadCount;
    }

    public function setThreadCount(int $threadCount): static
    {
        $this->threadCount = max(0, $threadCount);

        return $this;
    }

    public function getPostCount(): int
    {
        return $this->postCount;
    }

    public function setPostCount(int $postCount): static
    {
        $this->postCount = max(0, $postCount);

        return $this;
    }

    public function getLastPostAt(): ?\DateTimeImmutable
    {
        return $this->lastPostAt;
    }

    public function setLastPostAt(?\DateTimeImmutable $at): static
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isTrash(): bool
    {
        return $this->trash;
    }

    public function setTrash(bool $trash): static
    {
        $this->trash = $trash;

        return $this;
    }

    /** @return Collection<int, ForumModerator> */
    public function getModerators(): Collection
    {
        return $this->moderators;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
