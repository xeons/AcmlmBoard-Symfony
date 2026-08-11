<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DailyStatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per day of cumulative board totals; the stats page diffs consecutive rows
 * to show daily deltas. The original wrote this with a blind INSERT followed by an
 * UPDATE on *every page load*, relying on a duplicate-key error to no-op.
 */
#[ORM\Entity(repositoryClass: DailyStatRepository::class)]
#[ORM\Table(name: 'daily_stats')]
#[ORM\UniqueConstraint(name: 'uniq_daily_stat_date', columns: ['date'])]
class DailyStat
{
    /**
     * A surrogate key rather than the date itself.
     *
     * Doctrine stringifies identifiers for its identity map, and a DateTimeImmutable
     * has no string conversion - so a date-typed primary key throws the moment the
     * table has a row in it. The uniqueness that actually matters is enforced by the
     * index above.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(options: ['default' => 0])]
    private int $users = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $threads = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $posts = 0;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $views = 0;

    public function __construct(\DateTimeImmutable $date)
    {
        $this->date = $date;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getUsers(): int
    {
        return $this->users;
    }

    public function setUsers(int $users): static
    {
        $this->users = $users;

        return $this;
    }

    public function getThreads(): int
    {
        return $this->threads;
    }

    public function setThreads(int $threads): static
    {
        $this->threads = $threads;

        return $this;
    }

    public function getPosts(): int
    {
        return $this->posts;
    }

    public function setPosts(int $posts): static
    {
        $this->posts = $posts;

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
}
