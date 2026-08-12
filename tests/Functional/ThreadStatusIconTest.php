<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Thread;
use App\Service\BoardStatsService;
use App\Tests\Support\BoardWebTestCase;

/**
 * The marker in the leftmost column of a thread listing.
 *
 * forum.php decided this with four lines that are easy to get subtly wrong:
 *
 *     if (new)    { new = new.gif; if (hot) new = hotnew.gif }
 *     else        { new = blank;   if (hot) new = hot.gif }
 *     if (closed)        new = off.gif
 *     if (hot && closed) new = hotoff.gif
 *
 * So "hot" combines with the other two rather than competing with them, and
 * closed beats new. A port that reads the three as one if/elseif chain silently
 * loses two of the five states, which is what happened here.
 */
final class ThreadStatusIconTest extends BoardWebTestCase
{
    public function testAThreadWithUnreadPostsIsMarkedNew(): void
    {
        $this->signInAs('Member');

        self::assertSame('status-new', $this->statusFor($this->id('thread', 'open')));
    }

    public function testAThreadWithNothingUnreadHasNoMarker(): void
    {
        $this->signInAs('Member');
        $this->markForumRead();

        self::assertNull(
            $this->statusFor($this->id('thread', 'open')),
            'A forum that has just been marked read should show no new-post markers.',
        );
    }

    public function testABusyThreadWithUnreadPostsIsMarkedHotAndNew(): void
    {
        $this->makeEverythingHot();
        $this->signInAs('Member');

        self::assertSame('status-hot-new', $this->statusFor($this->id('thread', 'open')));
    }

    public function testABusyThreadWithNothingUnreadIsMarkedHot(): void
    {
        $this->makeEverythingHot();
        $this->signInAs('Member');
        $this->markForumRead();

        self::assertSame('status-hot', $this->statusFor($this->id('thread', 'open')));
    }

    /** Closed wins over new: the original applied it last and unconditionally. */
    public function testAClosedThreadIsMarkedClosedEvenWithUnreadPosts(): void
    {
        $this->signInAs('Member');

        self::assertSame('status-closed', $this->statusFor($this->id('thread', 'closed')));
    }

    public function testAClosedBusyThreadGetsTheCombinedMarker(): void
    {
        $this->makeEverythingHot();
        $this->signInAs('Member');

        self::assertSame('status-hot-closed', $this->statusFor($this->id('thread', 'closed')));
    }

    // ------------------------------------------------------------------

    public function testTheForumListMarksForumsWithUnreadPosts(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/');

        self::assertGreaterThan(
            0,
            $crawler->filter('.status-icon.status-new')->count(),
            'The forum list should mark forums holding unread posts.',
        );
    }

    /**
     * The markers are drawn from CSS custom properties so a scheme can replace the
     * set the way it replaces a palette. If a state has no image behind it the
     * marker renders as an empty box, which reads as no marker at all.
     */
    public function testEveryMarkerHasAnImageBehindIt(): void
    {
        $css = (string) file_get_contents(
            $this->container()->getParameter('kernel.project_dir').'/public/css/board.css',
        );

        foreach (['new', 'hot', 'hot-new', 'closed', 'hot-closed'] as $state) {
            self::assertMatchesRegularExpression(
                '/\.status-'.preg_quote($state, '/').'\s*\{[^}]*background-image:\s*var\(--icon-'.preg_quote($state, '/').'\)/',
                $css,
                \sprintf('.status-%s has no image behind it.', $state),
            );
            self::assertMatchesRegularExpression(
                '/--icon-'.preg_quote($state, '/').':\s*url\(/',
                $css,
                \sprintf('--icon-%s is not defined.', $state),
            );
        }
    }

    /** Whatever the CSS points at has to be on disk, or the column renders blank. */
    public function testTheMarkerImagesExist(): void
    {
        $projectDir = $this->container()->getParameter('kernel.project_dir');
        $css = (string) file_get_contents($projectDir.'/public/css/board.css');

        preg_match_all('/--icon-[a-z-]+:\s*url\("([^"]+)"\)/', $css, $matches);
        self::assertCount(5, $matches[1], 'Expected five marker images.');

        foreach ($matches[1] as $relative) {
            self::assertFileExists(
                $projectDir.'/public/css/'.$relative,
                \sprintf('The marker image %s is missing.', $relative),
            );
        }
    }

    // ------------------------------------------------------------------

    /**
     * The modifier class on the marker in this thread's row, or null when the row
     * carries no marker at all.
     */
    private function statusFor(int $threadId): ?string
    {
        $crawler = $this->assertPageLoads('/forum/'.$this->id('forum', 'General discussion'));

        $rows = $crawler->filter('tr')->reduce(
            static fn ($row): bool => $row->filter('a[href="/thread/'.$threadId.'"]')->count() > 0,
        );
        self::assertGreaterThan(0, $rows->count(), \sprintf('Thread %d is not in the listing.', $threadId));

        $icons = $rows->first()->filter('.status-icon');
        if (0 === $icons->count()) {
            return null;
        }

        $classes = array_values(array_filter(
            explode(' ', (string) $icons->first()->attr('class')),
            static fn (string $class): bool => '' !== $class && 'status-icon' !== $class,
        ));

        return $classes[0] ?? null;
    }

    /** One reply is enough to be hot, which every seeded thread's opening post is not. */
    private function makeEverythingHot(): void
    {
        $stats = $this->container()->get(BoardStatsService::class)->get();
        $stats->setHotThreadThreshold(1);

        // The seeded threads carry a single opening post and no replies, so the
        // reply counter has to be nudged for the threshold to bite.
        foreach ([$this->id('thread', 'open'), $this->id('thread', 'closed')] as $id) {
            $this->em()->find(Thread::class, $id)?->setReplies(1);
        }

        $this->em()->flush();
    }

    private function markForumRead(): void
    {
        $this->post(
            '/forum/'.$this->id('forum', 'General discussion').'/mark-read',
            [],
            'mark-read',
        );
        $this->assertRedirectsTo('/forum/');
    }
}
