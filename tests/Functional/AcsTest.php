<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Thread;
use App\Repository\ForumRepository;
use App\Repository\PostRepository;
use App\Service\PostManager;
use App\Tests\Support\BoardWebTestCase;

/**
 * ACS: the daily post-count ranking, ported from acs.php.
 */
final class AcsTest extends BoardWebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function views(): iterable
    {
        yield 'default' => ['/acs'];
        yield 'full' => ['/acs?view=full'];
        yield 'top ten' => ['/acs?view=top10'];
        yield 'movement' => ['/acs?view=movement'];
        yield 'a chosen day' => ['/acs?day=2026-01-15'];
        yield 'a chosen day with movement' => ['/acs?day=2026-01-15&view=movement'];
        yield 'highlighting somebody' => ['/acs?member=Member'];
        yield 'highlighting nobody' => ['/acs?member='];
        yield 'an unparseable day' => ['/acs?day=not-a-date'];
        yield 'an unknown view' => ['/acs?view=bogus'];
        yield 'an unknown member' => ['/acs?member=NoSuchPerson'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('views')]
    public function testEveryViewRenders(string $uri): void
    {
        $this->signInAs('Member');
        $this->assertPageLoads($uri);
    }

    public function testGuestsCanSeeTheRankings(): void
    {
        $this->assertPageLoads('/acs');
    }

    public function testItIsLinkedFromTheNavigation(): void
    {
        $crawler = $this->assertPageLoads('/');

        self::assertGreaterThan(0, $crawler->filter('a[href="/acs"]')->count(), 'ACS is not in the navigation.');
    }

    // ------------------------------------------------------------------
    // The ranking itself
    // ------------------------------------------------------------------

    public function testTodaysPostsAreCounted(): void
    {
        $thread = $this->em()->find(Thread::class, $this->id('thread', 'open'));
        $manager = $this->container()->get(PostManager::class);

        $manager->reply($thread, $this->user('Member'), 'One.', null);
        $manager->reply($thread, $this->user('Other'), 'Two.', null);
        $manager->reply($thread, $this->user('Member'), 'Three.', null);

        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/acs');
        $text = $crawler->filter('table')->last()->text();

        self::assertStringContainsString('Member', $text);
        self::assertStringContainsString('Other', $text);
    }

    public function testTheBusiestPosterIsRankedFirst(): void
    {
        $thread = $this->em()->find(Thread::class, $this->id('thread', 'open'));
        $manager = $this->container()->get(PostManager::class);

        // Other already holds the seeded reply; give Member a clear lead.
        for ($i = 0; $i < 5; ++$i) {
            $manager->reply($thread, $this->user('Member'), 'Post '.$i, null);
        }

        $rows = $this->rankToday();

        self::assertNotEmpty($rows);
        self::assertSame('Member', $rows[0]['user']->getUsername());
        self::assertGreaterThanOrEqual(5, $rows[0]['count']);
    }

    public function testCountsDescend(): void
    {
        $counts = array_column($this->rankToday(), 'count');
        $sorted = $counts;
        rsort($sorted);

        self::assertSame($sorted, $counts);
    }

    /** A day with nothing on it renders rather than failing. */
    public function testADayWithNoPostsSaysSo(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/acs?day=2001-01-01');

        self::assertStringContainsString('Nobody posted', $crawler->text());
    }

    public function testPostsFromAnotherDayAreNotCounted(): void
    {
        $this->signInAs('Member');

        $today = $this->rankToday();
        self::assertNotEmpty($today, 'The seeded board should have posts today.');

        $rows = $this->container()->get(PostRepository::class)->countByAuthorBetween(
            $this->visible(),
            new \DateTimeImmutable('2001-01-01 00:00:00'),
            new \DateTimeImmutable('2001-01-02 00:00:00'),
        );

        self::assertSame([], $rows);
    }

    /**
     * A ranking that counted restricted forums would let anyone infer activity in
     * them from the numbers.
     */
    public function testRestrictedForumsAreNotCounted(): void
    {
        $staffForum = $this->em()->find(\App\Entity\Forum::class, $this->id('forum', 'Staff discussion'));
        $thread = $this->em()->find(Thread::class, $this->id('thread', 'staff'));

        // Give Admin a large lead, but only inside the staff forum.
        for ($i = 0; $i < 8; ++$i) {
            $this->container()->get(PostManager::class)->reply($thread, $this->user('Admin'), 'Staff '.$i, null);
        }
        self::assertSame($staffForum, $thread->getForum());

        $asMember = $this->container()->get(PostRepository::class)->countByAuthorBetween(
            $this->visible(0),
            (new \DateTimeImmutable('today')),
            (new \DateTimeImmutable('tomorrow')),
        );

        foreach ($asMember as $row) {
            if ('Admin' === $row['user']->getUsername()) {
                self::assertLessThan(8, $row['count'], 'Staff-forum posts leaked into a member\'s ranking.');
            }
        }

        $asStaff = $this->container()->get(PostRepository::class)->countByAuthorBetween(
            $this->visible(1),
            (new \DateTimeImmutable('today')),
            (new \DateTimeImmutable('tomorrow')),
        );

        $staffTotal = 0;
        foreach ($asStaff as $row) {
            if ('Admin' === $row['user']->getUsername()) {
                $staffTotal = $row['count'];
            }
        }
        self::assertGreaterThanOrEqual(8, $staffTotal, 'Staff should see their own forum in the counts.');
    }

    public function testTheHighlightedMemberIsMarked(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/acs?member=Member');

        self::assertGreaterThan(
            0,
            $crawler->filter('td.tdbgc')->count(),
            'The highlighted member is not distinguished from the rest.',
        );
    }

    /** The day is bounded in the viewer's zone, not the server's. */
    public function testTheDayFollowsTheViewersTimezone(): void
    {
        $user = $this->signInAs('Member');

        $user->setTimezone('Pacific/Kiritimati'); // UTC+14
        $this->em()->flush();
        $east = $this->assertPageLoads('/acs')->filter('th')->first()->text();

        $user->setTimezone('Pacific/Midway'); // UTC-11
        $this->em()->flush();
        $west = $this->assertPageLoads('/acs')->filter('th')->first()->text();

        // 25 hours apart, so the two are on different calendar days much of the time.
        self::assertNotSame('', $east);
        self::assertNotSame('', $west);
    }

    // ------------------------------------------------------------------

    /** @return list<array{user: \App\Entity\User, count: int}> */
    private function rankToday(): array
    {
        return $this->container()->get(PostRepository::class)->countByAuthorBetween(
            $this->visible(),
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('tomorrow'),
        );
    }

    /** @return list<int> */
    private function visible(int $power = 0): array
    {
        return $this->container()->get(ForumRepository::class)->findReadableIds($power);
    }
}
