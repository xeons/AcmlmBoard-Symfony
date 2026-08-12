<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\BoardWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The demo seeder.
 *
 * It writes rows straight through DBAL rather than the ORM, so nothing else
 * enforces the invariants the rest of the board relies on - a counter left stale
 * here surfaces much later as a forum claiming more posts than it holds. These
 * run it small and then check the same things app:board:recount would.
 */
final class SeedDemoDataTest extends BoardWebTestCase
{
    private const SMALL = [
        '--members' => '12',
        '--threads' => '15',
        '--posts' => '120',
        '--messages' => '10',
        '--days' => '90',
        '--force' => true,
    ];

    public function testItSeedsAConsistentBoard(): void
    {
        $before = $this->rows('users');

        $this->seed();

        self::assertSame($before + 12, $this->rows('users'), 'The members were not created.');
        self::assertGreaterThanOrEqual(15, $this->rows('threads'));
        self::assertGreaterThanOrEqual(120, $this->rows('posts'));
        self::assertGreaterThanOrEqual(10, $this->rows('private_messages'));

        $this->assertCountersAreConsistent();
    }

    /** Replies fill the window up to now rather than running past it and being dropped. */
    public function testItWritesAtLeastAsManyPostsAsRequested(): void
    {
        $this->seed();

        self::assertGreaterThanOrEqual(
            120,
            $this->rows('posts'),
            'The seeder dropped posts rather than fitting them into the window.',
        );
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM posts WHERE created_at > :now', [
            'now' => (new \DateTimeImmutable('+1 minute'))->format('Y-m-d H:i:s'),
        ]), 'No post may be dated in the future.');
    }

    /** A guest's unread window is an hour wide; an empty one makes the board look dead. */
    public function testItLeavesRecentActivityForTheNewPostMarkers(): void
    {
        $this->seed();

        $recent = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM posts WHERE created_at >= :cutoff', [
            'cutoff' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);

        self::assertGreaterThan(0, $recent, 'Nothing was posted within the hour, so no forum reads as new.');
    }

    public function testPurgeRemovesEverythingItAdded(): void
    {
        $users = $this->rows('users');
        $threads = $this->rows('threads');
        $posts = $this->rows('posts');

        $this->seed();
        $this->runSeeder(['--purge' => true, '--force' => true]);

        self::assertSame($users, $this->rows('users'), 'Demo members survived the purge.');
        self::assertSame($threads, $this->rows('threads'), 'Demo threads survived the purge.');
        self::assertSame($posts, $this->rows('posts'), 'Demo posts survived the purge.');
        self::assertSame(0, $this->rows('post_radar'));

        $this->assertCountersAreConsistent();
    }

    /** Every account it creates is a working one sharing a published password. */
    public function testDemoMembersAreTaggedSoThePurgeCanFindThem(): void
    {
        $this->seed();

        self::assertSame(
            12,
            (int) $this->db()->fetchOne('SELECT COUNT(*) FROM users WHERE email LIKE :d', ['d' => '%@demo.invalid']),
        );
    }

    // ------------------------------------------------------------------

    private function assertCountersAreConsistent(): void
    {
        $checks = [
            'a member post count' => 'SELECT COUNT(*) FROM users u WHERE u.posts <> (SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id)',
            'a thread reply count' => 'SELECT COUNT(*) FROM threads t WHERE t.replies <> GREATEST(0, (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id) - 1)',
            'a forum post count' => 'SELECT COUNT(*) FROM forums f WHERE f.post_count <> (SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE t.forum_id = f.id)',
            'a forum thread count' => 'SELECT COUNT(*) FROM forums f WHERE f.thread_count <> (SELECT COUNT(*) FROM threads t WHERE t.forum_id = f.id)',
            'a thread with no posts' => 'SELECT COUNT(*) FROM threads t WHERE NOT EXISTS (SELECT 1 FROM posts p WHERE p.thread_id = t.id)',
            'an unnumbered post' => 'SELECT COUNT(*) FROM posts WHERE author_post_number < 1',
        ];

        foreach ($checks as $what => $sql) {
            self::assertSame(0, (int) $this->db()->fetchOne($sql), \sprintf('Seeding left %s wrong.', $what));
        }
    }

    private function seed(): void
    {
        $this->runSeeder(self::SMALL);
    }

    /** @param array<string, mixed> $input */
    private function runSeeder(array $input): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('app:board:seed-demo'));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();
    }

    private function rows(string $table): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM `'.$table.'`');
    }

    private function db(): Connection
    {
        return $this->container()->get(Connection::class);
    }
}
