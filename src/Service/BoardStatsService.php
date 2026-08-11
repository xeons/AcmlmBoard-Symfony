<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BoardStats;
use App\Repository\BoardStatsRepository;
use App\Repository\DailyStatRepository;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Board-wide totals and records.
 *
 * The index page shows total users, threads, posts, posts in the last day and posts
 * in the last hour. The original computed all five with COUNT(*) over the full
 * tables on every single request, from every visitor, logged in or not - five full
 * scans per pageview. They are cached for a minute here, which is well inside the
 * resolution anyone reads them at.
 */
final class BoardStatsService
{
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly BoardStatsRepository $boardStats,
        private readonly DailyStatRepository $dailyStats,
        private readonly UserRepository $users,
        private readonly ThreadRepository $threads,
        private readonly PostRepository $posts,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{users: int, threads: int, posts: int, postsToday: int, postsThisHour: int}
     */
    public function totals(): array
    {
        return $this->cache->get('board.totals', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return [
                'users' => $this->users->countAll(),
                'threads' => $this->threads->countAll(),
                'posts' => $this->posts->countAll(),
                'postsToday' => $this->posts->countSince(new \DateTimeImmutable('-1 day')),
                'postsThisHour' => $this->posts->countSince(new \DateTimeImmutable('-1 hour')),
            ];
        });
    }

    public function get(): BoardStats
    {
        return $this->boardStats->get();
    }

    public function incrementPageViews(): int
    {
        return $this->boardStats->incrementPageViews();
    }

    /**
     * Updates the "most posts in a day/hour" and "most users online" records.
     * Called from the scheduled maintenance command, not from page rendering.
     *
     * @param list<string> $onlineNames
     */
    public function refreshRecords(int $onlineCount, array $onlineNames, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $stats = $this->boardStats->get();
        $totals = $this->totals();

        $changed = $stats->recordPostsInDay($totals['postsToday'], $now);
        $changed = $stats->recordPostsInHour($totals['postsThisHour'], $now) || $changed;
        $changed = $stats->recordUsersOnline($onlineCount, $now, $onlineNames) || $changed;

        if ($changed) {
            $this->em->flush();
        }
    }

    /** Writes today's cumulative snapshot for the daily-stats table. */
    public function recordDailySnapshot(?\DateTimeImmutable $day = null): void
    {
        $day ??= new \DateTimeImmutable('today');
        $totals = $this->totals();

        $this->dailyStats->record(
            $day,
            $totals['users'],
            $totals['threads'],
            $totals['posts'],
            $this->boardStats->get()->getPageViews(),
        );
    }
}
