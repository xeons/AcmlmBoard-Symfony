<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoardStatsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-row table of board-wide counters and records (the original `misc` table).
 * Always id = 1; BoardStatsService is the only writer.
 */
#[ORM\Entity(repositoryClass: BoardStatsRepository::class)]
#[ORM\Table(name: 'board_stats')]
class BoardStats
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $pageViews = 0;

    /** Replies needed before a thread gets the "hot" icon. 0 disables the feature. */
    #[ORM\Column(options: ['default' => 30])]
    private int $hotThreadThreshold = 30;

    #[ORM\Column(options: ['default' => 0])]
    private int $maxPostsInDay = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $maxPostsInDayAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $maxPostsInHour = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $maxPostsInHourAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $maxUsersOnline = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $maxUsersOnlineAt = null;

    /**
     * Usernames present at the record. Stored as a list rather than the original's
     * pre-rendered HTML blob, which meant the record line could never be restyled.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $maxUsersOnlineNames = [];

    public function getId(): int
    {
        return $this->id;
    }

    public function getPageViews(): int
    {
        return $this->pageViews;
    }

    public function setPageViews(int $pageViews): static
    {
        $this->pageViews = $pageViews;

        return $this;
    }

    public function getHotThreadThreshold(): int
    {
        return $this->hotThreadThreshold;
    }

    public function setHotThreadThreshold(int $threshold): static
    {
        $this->hotThreadThreshold = max(0, $threshold);

        return $this;
    }

    public function getMaxPostsInDay(): int
    {
        return $this->maxPostsInDay;
    }

    public function getMaxPostsInDayAt(): ?\DateTimeImmutable
    {
        return $this->maxPostsInDayAt;
    }

    public function recordPostsInDay(int $count, \DateTimeImmutable $at): bool
    {
        if ($count <= $this->maxPostsInDay) {
            return false;
        }
        $this->maxPostsInDay = $count;
        $this->maxPostsInDayAt = $at;

        return true;
    }

    public function getMaxPostsInHour(): int
    {
        return $this->maxPostsInHour;
    }

    public function getMaxPostsInHourAt(): ?\DateTimeImmutable
    {
        return $this->maxPostsInHourAt;
    }

    public function recordPostsInHour(int $count, \DateTimeImmutable $at): bool
    {
        if ($count <= $this->maxPostsInHour) {
            return false;
        }
        $this->maxPostsInHour = $count;
        $this->maxPostsInHourAt = $at;

        return true;
    }

    public function getMaxUsersOnline(): int
    {
        return $this->maxUsersOnline;
    }

    public function getMaxUsersOnlineAt(): ?\DateTimeImmutable
    {
        return $this->maxUsersOnlineAt;
    }

    /** @return list<string> */
    public function getMaxUsersOnlineNames(): array
    {
        return $this->maxUsersOnlineNames;
    }

    /** @param list<string> $names */
    public function recordUsersOnline(int $count, \DateTimeImmutable $at, array $names): bool
    {
        if ($count <= $this->maxUsersOnline) {
            return false;
        }
        $this->maxUsersOnline = $count;
        $this->maxUsersOnlineAt = $at;
        $this->maxUsersOnlineNames = $names;

        return true;
    }
}
