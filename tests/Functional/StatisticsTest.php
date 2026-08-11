<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Thread;
use App\Repository\ForumRepository;
use App\Repository\PostRepository;
use App\Service\PostManager;
use App\Tests\Support\BoardWebTestCase;

/**
 * Post statistics: by forum, by thread and by hour of day, over a time window.
 * Ported from postsbyforum.php, postsbythread.php and postsbytime.php.
 */
final class StatisticsTest extends BoardWebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        foreach (['forums', 'threads', 'hours'] as $page) {
            foreach (['hour', 'day', 'week', 'month', 'all'] as $window) {
                yield $page.'/'.$window => ['/statistics/'.$page.'?window='.$window];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testEveryBreakdownRendersInEveryWindow(string $uri): void
    {
        $this->signInAs('Member');
        $this->assertPageLoads($uri);
    }

    public function testABreakdownCanBeNarrowedToOneMember(): void
    {
        $this->signInAs('Member');
        $id = $this->id('user', 'Member');

        foreach (['forums', 'threads', 'hours'] as $page) {
            $crawler = $this->assertPageLoads('/statistics/'.$page.'?user='.$id.'&window=all');
            self::assertStringContainsString('Member', $crawler->filter('table')->first()->text());
        }
    }

    public function testAnUnknownMemberIsTreatedAsNoFilter(): void
    {
        $this->signInAs('Member');
        $this->assertPageLoads('/statistics/hours?user=999999');
    }

    public function testAnUnknownWindowFallsBackInsteadOfFailing(): void
    {
        $this->signInAs('Member');
        $this->assertPageLoads('/statistics/forums?window=not-a-window');
    }

    // ------------------------------------------------------------------
    // The counts themselves
    // ------------------------------------------------------------------

    public function testForumCountsMatchTheRealNumbers(): void
    {
        $general = $this->id('forum', 'General discussion');

        $rows = $this->posts()->countByForum($this->visibleTo(0));
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['forum']->getId()] = $row['count'];
        }

        $actual = (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE t.forum_id = ?',
            [$general],
        );

        self::assertSame($actual, $counts[$general] ?? 0);
    }

    public function testCountsAreOrderedWithTheBusiestFirst(): void
    {
        $rows = $this->posts()->countByForum($this->visibleTo(0));
        $counts = array_column($rows, 'count');

        $descending = $counts;
        rsort($descending);

        self::assertSame($descending, $counts);
    }

    /** A restricted forum must not show up in a count for someone who cannot read it. */
    public function testABreakdownNeverRevealsARestrictedForum(): void
    {
        $memberScope = $this->posts()->countByForum($this->visibleTo(0));
        $staffScope = $this->posts()->countByForum($this->visibleTo(1));

        $memberTitles = array_map(static fn (array $r): string => $r['forum']->getTitle(), $memberScope);
        $staffTitles = array_map(static fn (array $r): string => $r['forum']->getTitle(), $staffScope);

        self::assertNotContains('Staff discussion', $memberTitles);
        self::assertContains('Staff discussion', $staffTitles);
    }

    public function testTheStaffForumIsAbsentFromAMembersPage(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/statistics/forums?window=all');

        self::assertStringNotContainsString('Staff discussion', $crawler->text());
    }

    public function testThreadCountsAreScopedToTheChosenMember(): void
    {
        $thread = $this->em()->find(Thread::class, $this->id('thread', 'open'));
        $this->container()->get(PostManager::class)->reply($thread, $this->user('Member'), 'One more.', null);

        $rows = $this->posts()->countByThread($this->visibleTo(0), $this->user('Member'));
        $forThisThread = 0;
        foreach ($rows as $row) {
            if ($row['thread']->getId() === $thread->getId()) {
                $forThisThread = $row['count'];
            }
        }

        $actual = (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM posts WHERE thread_id = ? AND author_id = ?',
            [$thread->getId(), $this->user('Member')->getId()],
        );

        self::assertSame($actual, $forThisThread);
    }

    public function testTheHourHistogramAlwaysHasTwentyFourBuckets(): void
    {
        $hours = $this->posts()->countByHourOfDay($this->visibleTo(0));

        self::assertCount(24, $hours);
        self::assertSame(range(0, 23), array_keys($hours));
    }

    public function testTheHourHistogramTotalsAllVisiblePosts(): void
    {
        $hours = $this->posts()->countByHourOfDay($this->visibleTo(0));

        $visible = (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM posts p
               JOIN threads t ON t.id = p.thread_id
               JOIN forums f ON f.id = t.forum_id
              WHERE f.min_power <= 0',
        );

        self::assertSame($visible, array_sum($hours));
    }

    /** The buckets rotate with the viewer's zone rather than showing server time. */
    public function testTheHistogramIsShiftedIntoTheViewersTimezone(): void
    {
        $utc = $this->posts()->countByHourOfDay($this->visibleTo(0), offsetMinutes: 0);
        $plusFive = $this->posts()->countByHourOfDay($this->visibleTo(0), offsetMinutes: 300);

        self::assertSame(array_sum($utc), array_sum($plusFive), 'Rotating must not lose posts.');

        // Every bucket moves five places along.
        foreach ($utc as $hour => $count) {
            self::assertSame($count, $plusFive[($hour + 5) % 24], 'Bucket '.$hour);
        }
    }

    public function testAWindowExcludesOlderPosts(): void
    {
        // The seeded posts are all recent, so an hour-long window still sees them,
        // but a window ending before they existed must not.
        $future = new \DateTimeImmutable('+1 day');

        self::assertSame(0, array_sum($this->posts()->countByHourOfDay($this->visibleTo(0), since: $future)));
        self::assertGreaterThan(0, array_sum($this->posts()->countByHourOfDay($this->visibleTo(0))));
    }

    // ------------------------------------------------------------------

    private function posts(): PostRepository
    {
        return $this->container()->get(PostRepository::class);
    }

    /** @return list<int> */
    private function visibleTo(int $power): array
    {
        return $this->container()->get(ForumRepository::class)->findReadableIds($power);
    }

}
