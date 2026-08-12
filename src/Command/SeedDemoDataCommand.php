<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fills a board with plausible demo traffic: members, threads, posts, messages
 * and the incidental rows that make the community pages worth looking at.
 *
 * This exists because an empty board hides nearly every bug worth finding. The
 * rank ladders, the ACS rankings, pagination, the hot-thread markers, the "posts
 * by hour" breakdown and the percentile ranks are all invisible until there is a
 * population to compute them from.
 *
 * Everything it writes is tagged by an @demo.invalid address - a reserved TLD
 * that can never belong to a real person - so --purge can take it all out again
 * without touching anything real.
 */
#[AsCommand(
    name: 'app:board:seed-demo',
    description: 'Fills the board with demo members, threads and posts. Never for a public board.',
)]
final class SeedDemoDataCommand extends Command
{
    /** Reserved by RFC 2606, so it cannot collide with a real member. */
    private const DEMO_EMAIL_DOMAIN = '@demo.invalid';

    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('members', null, InputOption::VALUE_REQUIRED, 'How many demo members to create.', '250')
            ->addOption('threads', null, InputOption::VALUE_REQUIRED, 'How many threads to start.', '600')
            ->addOption('posts', null, InputOption::VALUE_REQUIRED, 'How many posts in total, replies included.', '12000')
            ->addOption('messages', null, InputOption::VALUE_REQUIRED, 'How many private messages to exchange.', '400')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How far back the history should stretch.', '900')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'The password every demo member shares.', 'demo-password-123')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete previously seeded demo data and stop.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment && !$input->getOption('force')) {
            $io->error('Refusing to seed demo data in the prod environment. Pass --force if this really is what you want.');

            return Command::FAILURE;
        }

        if ($input->getOption('purge')) {
            return $this->purge($io, (bool) $input->getOption('force'));
        }

        $members = max(1, (int) $input->getOption('members'));
        $threads = max(1, (int) $input->getOption('threads'));
        $posts = max($threads, (int) $input->getOption('posts'));
        $messages = max(0, (int) $input->getOption('messages'));
        $days = max(1, (int) $input->getOption('days'));

        $io->title('Seeding demo data');
        $io->definitionList(
            ['Members' => number_format($members)],
            ['Threads' => number_format($threads)],
            ['Posts' => number_format($posts)],
            ['Messages' => number_format($messages)],
            ['History' => $days.' days'],
        );

        if (!$input->getOption('force') && !$io->confirm('This adds rows to the configured database. Continue?', false)) {
            $io->warning('Nothing was written.');

            return Command::SUCCESS;
        }

        // One hash, reused. Hashing 250 passwords properly would dominate the
        // runtime of the whole command for no benefit; they all share a password
        // anyway, and it is the real hasher, so the accounts genuinely work.
        $hash = $this->hasher->hashPassword(new User(), (string) $input->getOption('password'));

        $now = new \DateTimeImmutable();
        $start = $now->modify('-'.$days.' days');

        $this->db->beginTransaction();

        try {
            $io->section('Members');
            $memberIds = $this->seedMembers($io, $members, $hash, $start, $now);

            $io->section('Threads and posts');
            $this->seedThreadsAndPosts($io, $memberIds, $threads, $posts, $start, $now);

            $io->section('Community');
            $this->seedMessages($io, $memberIds, $messages, $start, $now);
            $this->seedFavouritesRatingsAndRadar($io, $memberIds);
            $this->seedReadMarkers($io, $memberIds);
            $this->seedCalendar($io, $memberIds, $now);

            $io->section('Derived counters');
            $this->recount($io);
            $this->rebuildDailyStats($io);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $io->error('Rolled back: '.$e->getMessage());

            throw $e;
        }

        $io->success('Demo data seeded.');
        $io->note(\sprintf('Every demo member signs in with the password "%s".', $input->getOption('password')));
        $io->note('Run "app:board:maintenance" to fill in the percentile rank thresholds.');

        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Members
    // ------------------------------------------------------------------

    /** @return list<int> */
    private function seedMembers(SymfonyStyle $io, int $count, string $hash, \DateTimeImmutable $start, \DateTimeImmutable $now): array
    {
        $existing = $this->db->fetchFirstColumn('SELECT username_canonical FROM users');
        $taken = array_flip($existing);

        $rankSets = $this->db->fetchFirstColumn('SELECT id FROM rank_sets');
        $schemes = $this->db->fetchFirstColumn('SELECT id FROM color_schemes');
        $layouts = $this->db->fetchFirstColumn('SELECT id FROM thread_layouts');
        $avatars = $this->db->fetchFirstColumn('SELECT url FROM user_pictures ORDER BY RAND() LIMIT 400');

        $rows = [];

        for ($i = 0; $i < $count; ++$i) {
            $name = $this->uniqueHandle($taken);

            // Registration dates cluster towards the older end, so the board
            // reads as one that grew rather than one that appeared at once.
            $registered = $this->between($start, $now, $this->skewed(0.6));
            $lastActive = $this->between($registered, $now, $this->skewed(0.25));

            // A handful of staff, a couple of banned accounts, the rest members.
            $power = match (true) {
                $i > 0 && 0 === $i % 97 => 2,
                $i > 0 && 0 === $i % 41 => 1,
                $i > 0 && 0 === $i % 53 => -1,
                default => 0,
            };

            $rows[] = [
                'username' => $name,
                'username_canonical' => User::canonicalizeUsername($name),
                'password' => $hash,
                'password_legacy_md5' => 0,
                'email' => strtolower(preg_replace('/[^a-z0-9]/i', '', $name) ?: 'member').$i.self::DEMO_EMAIL_DOMAIN,
                'email_public' => random_int(0, 1),
                'power_level' => $power,
                'sex' => [0, 0, 1, 2, 3][random_int(0, 4)],
                'posts' => 0,
                'registered_at' => $registered->format('Y-m-d H:i:s'),
                'last_activity_at' => $lastActive->format('Y-m-d H:i:s'),
                'last_post_at' => null,
                'last_ip' => $this->ip(),
                'last_url' => null,
                'title' => random_int(1, 6) > 5 ? $this->pick(self::TITLES) : null,
                'title_option' => 1,
                'picture' => $avatars && random_int(1, 10) > 2 ? $this->pick($avatars) : null,
                'mini_pic' => null,
                'post_background' => null,
                'post_header' => null,
                'signature' => random_int(1, 3) > 1 ? $this->pick(self::SIGNATURES) : null,
                'bio' => random_int(1, 3) > 1 ? $this->pick(self::BIOS) : null,
                'real_name' => null,
                'location' => random_int(1, 2) > 1 ? $this->pick(self::LOCATIONS) : null,
                'birthday' => null,
                'birthday_month_day' => null,
                'homepage_url' => null,
                'homepage_name' => null,
                'posts_per_page' => 20,
                'threads_per_page' => 50,
                'signature_display' => 1,
                'signature_separator' => 0,
                'post_toolbar' => 1,
                'mark_read_outside_menu' => 0,
                'current_forum_id' => null,
                'rank_set_id' => $rankSets ? $this->pick($rankSets) : null,
                'color_scheme_id' => $schemes && random_int(1, 4) > 2 ? $this->pick($schemes) : null,
                'thread_layout_id' => $layouts && random_int(1, 5) > 3 ? $this->pick($layouts) : null,
                'timezone' => $this->pick(self::TIMEZONES),
                'webauthn_handle' => null,
                'totp_secret' => null,
                'totp_confirmed_at' => null,
                'totp_recovery_codes' => '[]',
            ];
        }

        $this->bulkInsert('users', $rows);

        $ids = $this->db->fetchFirstColumn(
            'SELECT id FROM users WHERE email LIKE :domain ORDER BY id',
            ['domain' => '%'.self::DEMO_EMAIL_DOMAIN],
        );
        $ids = array_map('intval', $ids);

        // Some birthdays land in the next fortnight so the calendar has something
        // to show rather than an empty grid.
        foreach (array_slice($ids, 0, min(40, \count($ids))) as $id) {
            $birthday = $now->modify('+'.random_int(0, 20).' days')->modify('-'.random_int(18, 45).' years');
            $this->db->update('users', [
                'birthday' => $birthday->format('Y-m-d'),
                // Same derivation as User::setBirthday, which is what the
                // birthday lookups index on.
                'birthday_month_day' => (int) $birthday->format('n') * 100 + (int) $birthday->format('j'),
            ], ['id' => $id]);
        }

        $io->writeln(\sprintf('  %d members created.', \count($ids)));

        return $ids;
    }

    // ------------------------------------------------------------------
    // Threads and posts
    // ------------------------------------------------------------------

    /** @param list<int> $memberIds */
    private function seedThreadsAndPosts(SymfonyStyle $io, array $memberIds, int $threadCount, int $postCount, \DateTimeImmutable $start, \DateTimeImmutable $now): void
    {
        /** @var list<array{id: int, min_power: int}> $forums */
        $forums = array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'min_power' => (int) $r['min_power']],
            $this->db->fetchAllAssociative('SELECT f.id, f.min_power FROM forums f WHERE f.title <> :trash', ['trash' => 'Trash']),
        );
        if ([] === $forums) {
            throw new \RuntimeException('The board has no forums to post in.');
        }

        // Staff forums are drawn far less often than public ones. Only a handful
        // of accounts can post in them, so an equal share of the traffic would
        // hand that handful a four-figure post count and leave everyone else in
        // double figures, flattening the rank ladders.
        $forumWeights = [];
        $running = 0.0;
        foreach ($forums as $forum) {
            $running += $forum['min_power'] > 0 ? 1.0 : 8.0;
            $forumWeights[] = $running;
        }

        $staffIds = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT id FROM users WHERE power_level >= 1 AND email LIKE :domain',
            ['domain' => '%'.self::DEMO_EMAIL_DOMAIN],
        )) ?: $memberIds;

        // Posting is never evenly shared on a real board: a small core writes most
        // of it and the long tail posts a handful of times.
        $memberWeights = $this->zipfWeights(\count($memberIds));
        $staffWeights = $this->zipfWeights(\count($staffIds));

        // Replies are spread with a long tail: most threads are short, a few run
        // to hundreds of posts. That is what makes pagination and the hot-thread
        // markers worth testing.
        $replyBudget = max(0, $postCount - $threadCount);
        $weights = [];
        $total = 0.0;
        for ($i = 0; $i < $threadCount; ++$i) {
            $w = 1 / (1 + $this->skewed(2.2) * 40);
            $weights[] = $w;
            $total += $w;
        }

        $plans = [];

        for ($i = 0; $i < $threadCount; ++$i) {
            $forum = $forums[$this->weightedIndex($forumWeights)];
            $isStaffOnly = $forum['min_power'] > 0;
            $pool = $isStaffOnly ? $staffIds : $memberIds;
            $poolWeights = $isStaffOnly ? $staffWeights : $memberWeights;

            $author = (int) $pool[$this->weightedIndex($poolWeights)];
            $createdAt = $this->postingMoment($this->between($start, $now, $this->skewed(0.5)));
            $replies = (int) round($replyBudget * ($weights[$i] / $total));

            // Inserted one at a time so the id comes back from the database
            // rather than being inferred from a block of auto-increment values.
            // Six hundred round trips inside one transaction cost very little,
            // and the posts below have to attach to exactly the right thread.
            $this->db->insert('threads', [
                'title' => $this->threadTitle(),
                'icon' => random_int(1, 4) > 3 ? $this->pick(self::ICONS) : null,
                'views' => random_int(0, 40) * max(1, $replies) + random_int(0, 200),
                'replies' => 0,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'last_post_at' => $createdAt->format('Y-m-d H:i:s'),
                'closed' => random_int(1, 25) > 24 ? 1 : 0,
                'sticky' => random_int(1, 60) > 59 ? 1 : 0,
                'locked' => 0,
                'forum_id' => $forum['id'],
                'author_id' => $author,
                'last_poster_id' => $author,
                'poll_id' => null,
            ]);

            $plans[] = [
                'id' => (int) $this->db->lastInsertId(),
                'author' => $author,
                'created' => $createdAt,
                'replies' => $replies,
                'pool' => $pool,
                'weights' => $poolWeights,
            ];
        }

        $io->writeln(\sprintf('  %d threads created.', \count($plans)));

        $postRows = [];
        $written = 0;

        foreach ($plans as $plan) {
            $threadId = $plan['id'];
            $when = $plan['created'];

            $postRows[] = $this->postRow($threadId, $plan['author'], $when, true);
            ++$written;

            // Replies fill the window between the thread's start and now, so a
            // thread started recently still gets its whole share.
            $remaining = max(3600, $now->getTimestamp() - $when->getTimestamp());
            $activeFor = max(3600, (int) round($remaining * $this->skewed(1.6)));

            $offsets = [];
            for ($r = 0; $r < $plan['replies']; ++$r) {
                // Skewed early: a thread is busiest just after it is posted.
                $offsets[] = (int) round($this->skewed(1.8) * $activeFor);
            }
            sort($offsets);

            foreach ($offsets as $offset) {
                $at = $this->postingMoment($plan['created']->modify('+'.$offset.' seconds'));
                if ($at > $now) {
                    $at = $now;
                }

                $postRows[] = $this->postRow($threadId, (int) $plan['pool'][$this->weightedIndex($plan['weights'])], $at, false);
                ++$written;
            }

            if (\count($postRows) >= self::INSERT_CHUNK) {
                $this->bulkInsert('posts', $postRows);
                $postRows = [];
            }
        }

        if ([] !== $postRows) {
            $this->bulkInsert('posts', $postRows);
        }

        // Something has to have happened in the last hour, or the board looks
        // abandoned: a guest's unread window is one hour wide, and postingMoment()
        // reassigns the hour of day, so the newest post otherwise lands somewhere
        // earlier in its own day and no forum shows a new-post marker at all.
        $recent = [];
        // array_rand returns a bare key rather than a list when asked for one.
        foreach ((array) array_rand($plans, min(40, \count($plans))) as $index) {
            $plan = $plans[(int) $index];
            $recent[] = $this->postRow(
                $plan['id'],
                (int) $plan['pool'][$this->weightedIndex($plan['weights'])],
                $now->modify('-'.random_int(2, 55).' minutes'),
                false,
            );
        }
        $this->bulkInsert('posts', $recent);
        $written += \count($recent);

        // replies, last_post_at and last_poster_id are all derived in recount().
        $io->writeln(\sprintf('  %d posts written, %d of them within the hour.', $written, \count($recent)));
    }

    /** @return array<string, mixed> */
    private function postRow(int $threadId, int $authorId, \DateTimeImmutable $when, bool $opening): array
    {
        return [
            'body' => $opening ? $this->openingPost() : $this->reply(),
            'created_at' => $when->format('Y-m-d H:i:s'),
            'ip' => $this->ip(),
            'author_post_number' => 0,
            'tag_values' => '{}',
            'edited_at' => null,
            'thread_id' => $threadId,
            'author_id' => $authorId,
            'header_layout_id' => null,
            'signature_layout_id' => null,
            'edited_by_id' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Community
    // ------------------------------------------------------------------

    /** @param list<int> $memberIds */
    private function seedMessages(SymfonyStyle $io, array $memberIds, int $count, \DateTimeImmutable $start, \DateTimeImmutable $now): void
    {
        if ($count < 1 || \count($memberIds) < 2) {
            return;
        }

        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $sender = (int) $memberIds[array_rand($memberIds)];
            do {
                $recipient = (int) $memberIds[array_rand($memberIds)];
            } while ($recipient === $sender);

            $sentAt = $this->between($start, $now, $this->skewed(0.4));
            $read = random_int(1, 4) > 1;

            $rows[] = [
                'title' => $this->pick(self::MESSAGE_SUBJECTS),
                'body' => $this->reply(),
                'created_at' => $sentAt->format('Y-m-d H:i:s'),
                'ip' => $this->ip(),
                'read_at' => $read ? $this->between($sentAt, $now, 0.2)->format('Y-m-d H:i:s') : null,
                'recipient_folder' => 1,
                'sender_folder' => 2,
                'system' => 0,
                'tag_values' => '{}',
                'sender_id' => $sender,
                'recipient_id' => $recipient,
                'header_layout_id' => null,
                'signature_layout_id' => null,
            ];
        }

        $this->bulkInsert('private_messages', $rows);
        $io->writeln(\sprintf('  %d private messages exchanged.', \count($rows)));
    }

    /** @param list<int> $memberIds */
    private function seedFavouritesRatingsAndRadar(SymfonyStyle $io, array $memberIds): void
    {
        $threadIds = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM threads ORDER BY RAND() LIMIT 400'));

        // Composite unique keys, so pairs are deduplicated before the insert
        // rather than relying on the database to reject the collisions.
        $favourites = [];
        for ($i = 0, $n = min(600, \count($memberIds) * 4); [] !== $threadIds && $i < $n; ++$i) {
            $user = (int) $memberIds[array_rand($memberIds)];
            $thread = (int) $threadIds[array_rand($threadIds)];
            $favourites[$user.':'.$thread] = [
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'user_id' => $user,
                'thread_id' => $thread,
            ];
        }
        if ([] !== $favourites) {
            $this->bulkInsert('favorites', array_values($favourites));
        }

        $ratings = [];
        for ($i = 0, $n = min(800, \count($memberIds) * 5); $i < $n; ++$i) {
            $rater = (int) $memberIds[array_rand($memberIds)];
            $rated = (int) $memberIds[array_rand($memberIds)];
            if ($rater === $rated) {
                continue;
            }
            $ratings[$rater.':'.$rated] = [
                'rating' => random_int(1, 5),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'rater_id' => $rater,
                'rated_id' => $rated,
            ];
        }
        if ([] !== $ratings) {
            $this->bulkInsert('user_ratings', array_values($ratings));
        }

        $radar = [];
        foreach (array_slice($memberIds, 0, 60) as $user) {
            for ($i = 0, $n = random_int(1, 4); $i < $n; ++$i) {
                $rival = (int) $memberIds[array_rand($memberIds)];
                if ($rival === $user) {
                    continue;
                }
                $radar[$user.':'.$rival] = ['user_id' => $user, 'rival_id' => $rival];
            }
        }
        if ([] !== $radar) {
            $this->bulkInsert('post_radar', array_values($radar));
        }

        $io->writeln(\sprintf(
            '  %d favourites, %d ratings, %d radar entries.',
            \count($favourites),
            \count($ratings),
            \count($radar),
        ));
    }

    /**
     * Read markers for some members, so the new-post indicators differ from
     * member to member instead of every thread reading as new for everyone.
     *
     * @param list<int> $memberIds
     */
    private function seedReadMarkers(SymfonyStyle $io, array $memberIds): void
    {
        $forumIds = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM forums'));
        $rows = [];

        foreach (array_slice($memberIds, 0, min(80, \count($memberIds))) as $user) {
            foreach ($forumIds as $forum) {
                if (random_int(1, 3) > 2) {
                    continue;
                }
                $rows[] = [
                    'user_id' => $user,
                    'forum_id' => $forum,
                    'read_at' => (new \DateTimeImmutable('-'.random_int(0, 400).' hours'))->format('Y-m-d H:i:s'),
                ];
            }
        }

        if ([] !== $rows) {
            $this->bulkInsert('forum_reads', $rows);
        }
        $io->writeln(\sprintf('  %d forum read markers.', \count($rows)));
    }

    /** @param list<int> $memberIds */
    private function seedCalendar(SymfonyStyle $io, array $memberIds, \DateTimeImmutable $now): void
    {
        $rows = [];
        foreach (self::EVENTS as $offset => $title) {
            $rows[] = [
                'date' => $now->modify('+'.($offset * 3 - 6).' days')->format('Y-m-d'),
                'annual' => 0 === $offset % 3 ? 1 : 0,
                'title' => $title,
                'body' => 'Added by the demo seeder.',
                'author_id' => $memberIds ? (int) $memberIds[array_rand($memberIds)] : null,
            ];
        }

        $this->bulkInsert('calendar_events', $rows);
        $io->writeln(\sprintf('  %d calendar events.', \count($rows)));
    }

    // ------------------------------------------------------------------
    // Derived data
    // ------------------------------------------------------------------

    /**
     * Everything above wrote rows without touching a counter, so the counters are
     * all derived here in bulk rather than incrementally. This is the same work
     * app:board:recount does.
     */
    private function recount(SymfonyStyle $io): void
    {
        // The author's Nth post, which the post header shows.
        $this->db->executeStatement(
            'UPDATE posts p
             JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY author_id ORDER BY created_at, id) AS rn
                FROM posts
             ) n ON n.id = p.id
             SET p.author_post_number = n.rn'
        );

        $this->db->executeStatement(
            'UPDATE threads t SET
                t.replies = GREATEST(0, (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id) - 1),
                t.last_post_at = COALESCE((SELECT MAX(p.created_at) FROM posts p WHERE p.thread_id = t.id), t.created_at)'
        );

        $this->db->executeStatement(
            'UPDATE threads t
             JOIN (
                SELECT p.thread_id, p.author_id
                FROM posts p
                JOIN (SELECT thread_id, MAX(id) AS max_id FROM posts GROUP BY thread_id) m
                  ON m.thread_id = p.thread_id AND m.max_id = p.id
             ) last ON last.thread_id = t.id
             SET t.last_poster_id = last.author_id'
        );

        $this->db->executeStatement('UPDATE users u SET u.posts = (SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id)');
        $this->db->executeStatement(
            'UPDATE users u SET u.last_post_at = (SELECT MAX(p.created_at) FROM posts p WHERE p.author_id = u.id)'
        );

        $this->db->executeStatement(
            'UPDATE forums f SET
                f.thread_count = (SELECT COUNT(*) FROM threads t WHERE t.forum_id = f.id),
                f.post_count = (SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE t.forum_id = f.id),
                f.last_post_at = (SELECT MAX(t.last_post_at) FROM threads t WHERE t.forum_id = f.id)'
        );

        $this->db->executeStatement(
            'UPDATE forums f
             JOIN (
                SELECT t.forum_id, t.last_poster_id
                FROM threads t
                JOIN (SELECT forum_id, MAX(last_post_at) AS m FROM threads GROUP BY forum_id) x
                  ON x.forum_id = t.forum_id AND x.m = t.last_post_at
             ) l ON l.forum_id = f.id
             SET f.last_poster_id = l.last_poster_id'
        );

        $io->writeln('  Counters rebuilt.');
    }

    /**
     * Rewrites the daily statistics history from the posts that actually exist.
     *
     * Called after seeding and again after purging, so the chart never outlives
     * the traffic it was drawn from - deleting the members without this leaves a
     * statistics page describing a board that is no longer there.
     */
    private function rebuildDailyStats(SymfonyStyle $io): void
    {
        $this->db->executeStatement('DELETE FROM daily_stats');

        // Cumulative totals as at the end of each day, which is what the original
        // statistics page charted.
        $rows = $this->db->fetchAllAssociative(
            'SELECT DATE(created_at) AS d, COUNT(*) AS posts FROM posts GROUP BY DATE(created_at) ORDER BY d'
        );

        $out = [];
        $runningPosts = 0;
        foreach ($rows as $row) {
            $runningPosts += (int) $row['posts'];
            $day = $row['d'];

            $out[] = [
                'date' => $day,
                'users' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE DATE(registered_at) <= :d', ['d' => $day]),
                'threads' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM threads WHERE DATE(created_at) <= :d', ['d' => $day]),
                'posts' => $runningPosts,
                'views' => $runningPosts * random_int(6, 14),
            ];
        }

        if ([] !== $out) {
            $this->bulkInsert('daily_stats', $out);
        }

        $this->db->executeStatement(
            'UPDATE board_stats SET
                page_views = (SELECT COALESCE(MAX(views), 0) FROM daily_stats)'
        );

        $io->writeln(\sprintf('  %d daily statistics rows.', \count($out)));
    }

    // ------------------------------------------------------------------
    // Purge
    // ------------------------------------------------------------------

    private function purge(SymfonyStyle $io, bool $force): int
    {
        $ids = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT id FROM users WHERE email LIKE :domain',
            ['domain' => '%'.self::DEMO_EMAIL_DOMAIN],
        ));

        if ([] === $ids) {
            $io->success('There is no demo data to remove.');

            return Command::SUCCESS;
        }

        $threads = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM threads WHERE author_id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $io->warning(\sprintf('This deletes %d demo members and the %d threads they started, posts included.', \count($ids), $threads));
        if (!$force && !$io->confirm('Continue?', false)) {
            return Command::SUCCESS;
        }

        $list = ['ids' => $ids];
        $types = ['ids' => ArrayParameterType::INTEGER];

        $this->db->beginTransaction();

        try {
            // Children first: nothing here relies on the schema's cascade rules
            // being set the way this command happens to need them.
            $this->db->executeStatement('DELETE FROM posts WHERE thread_id IN (SELECT id FROM threads WHERE author_id IN (:ids))', $list, $types);
            $this->db->executeStatement('DELETE FROM posts WHERE author_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM favorites WHERE thread_id IN (SELECT id FROM threads WHERE author_id IN (:ids))', $list, $types);
            $this->db->executeStatement('DELETE FROM threads WHERE author_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM private_messages WHERE sender_id IN (:ids) OR recipient_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM favorites WHERE user_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM user_ratings WHERE rater_id IN (:ids) OR rated_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM post_radar WHERE user_id IN (:ids) OR rival_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM forum_reads WHERE user_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM calendar_events WHERE author_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM forum_moderators WHERE user_id IN (:ids)', $list, $types);
            $this->db->executeStatement('DELETE FROM users WHERE id IN (:ids)', $list, $types);

            $this->recount($io);
            $this->rebuildDailyStats($io);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        $io->success('Demo data removed.');

        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * Multi-row INSERT in chunks. Nothing here needs the generated ids back - the
     * one place that does inserts a row at a time and asks the database.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function bulkInsert(string $table, array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $columns = array_keys($rows[0]);
        $quoted = implode(', ', array_map(static fn (string $c): string => '`'.$c.'`', $columns));

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $placeholders = [];
            $values = [];

            foreach ($chunk as $row) {
                $placeholders[] = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
                foreach ($columns as $column) {
                    $values[] = $row[$column];
                }
            }

            $this->db->executeStatement(
                'INSERT INTO `'.$table.'` ('.$quoted.') VALUES '.implode(', ', $placeholders),
                $values,
            );
        }
    }

    /** @param array<string, int> $taken canonical name => anything */
    private function uniqueHandle(array &$taken): string
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $name = match (random_int(1, 4)) {
                1 => $this->pick(self::HANDLE_ADJECTIVES).$this->pick(self::HANDLE_NOUNS),
                2 => $this->pick(self::HANDLE_NOUNS).random_int(1, 999),
                3 => $this->pick(self::HANDLE_ADJECTIVES).'_'.$this->pick(self::HANDLE_NOUNS),
                default => $this->pick(self::HANDLE_NOUNS).$this->pick(self::HANDLE_SUFFIXES),
            };
            $name = mb_substr($name, 0, 25);
            $canonical = User::canonicalizeUsername($name);

            if ('' !== $canonical && !isset($taken[$canonical])) {
                $taken[$canonical] = 1;

                return $name;
            }
        }

        // Fall back to something that cannot collide.
        $name = 'member'.random_int(100000, 999999);
        $taken[User::canonicalizeUsername($name)] = 1;

        return $name;
    }

    /**
     * Cumulative Zipf-ish weights for a pool of $count members: the first is
     * about twenty times as likely to post as the hundredth.
     *
     * The exponent is what decides whether the rank ladders are worth looking at.
     * Draw authors uniformly and 300 members all land within a few posts of the
     * mean, so every rung above the lowest is unreachable and the percentile set
     * collapses onto a single post count.
     *
     * @return list<float> cumulative, ascending, last entry is the total
     */
    private function zipfWeights(int $count): array
    {
        $cumulative = [];
        $running = 0.0;

        for ($i = 0; $i < $count; ++$i) {
            $running += 1 / (($i + 1) ** 0.85);
            $cumulative[] = $running;
        }

        return $cumulative;
    }

    /**
     * Picks an index from a cumulative weight table by binary search, so a pool
     * of thousands costs a handful of comparisons per draw rather than a scan.
     *
     * @param list<float> $cumulative
     */
    private function weightedIndex(array $cumulative): int
    {
        $n = \count($cumulative);
        if ($n < 2) {
            return 0;
        }

        $target = $this->unit() * $cumulative[$n - 1];

        $low = 0;
        $high = $n - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($cumulative[$mid] < $target) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    /**
     * A number in [0,1) pushed towards zero. Higher $bias means a longer tail,
     * which is what turns a flat distribution into one that looks like a forum.
     */
    private function skewed(float $bias): float
    {
        return $bias <= 0 ? mt_rand() / mt_getrandmax() : ($this->unit() ** $bias);
    }

    private function unit(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function between(\DateTimeImmutable $from, \DateTimeImmutable $to, float $position): \DateTimeImmutable
    {
        $span = $to->getTimestamp() - $from->getTimestamp();
        if ($span <= 0) {
            return $from;
        }

        return $from->setTimestamp($from->getTimestamp() + (int) round($span * max(0.0, min(1.0, $position))));
    }

    /**
     * Moves an instant to an hour somebody would plausibly be posting at, so the
     * "posts by hour of day" breakdown has a shape instead of being flat.
     */
    private function postingMoment(\DateTimeImmutable $when): \DateTimeImmutable
    {
        static $hours = [
            0 => 4, 1 => 3, 2 => 2, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 7 => 3,
            8 => 5, 9 => 6, 10 => 7, 11 => 7, 12 => 8, 13 => 8, 14 => 8, 15 => 9,
            16 => 10, 17 => 11, 18 => 12, 19 => 13, 20 => 14, 21 => 13, 22 => 10, 23 => 7,
        ];
        static $total = null;

        $total ??= array_sum($hours);

        $roll = random_int(1, $total);
        $hour = 20;
        foreach ($hours as $candidate => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                $hour = $candidate;
                break;
            }
        }

        return $when->setTime($hour, random_int(0, 59), random_int(0, 59));
    }

    private function ip(): string
    {
        // Documentation ranges only (RFC 5737), so nothing here can be mistaken
        // for a real address if it turns up in a moderation log.
        return $this->pick(['192.0.2.', '198.51.100.', '203.0.113.']).random_int(1, 254);
    }

    /**
     * @template T
     *
     * @param array<array-key, T> $values
     *
     * @return T
     */
    private function pick(array $values): mixed
    {
        return $values[array_rand($values)];
    }

    private function threadTitle(): string
    {
        return match (random_int(1, 5)) {
            1 => $this->pick(self::TOPIC_OPENERS).' '.$this->pick(self::TOPIC_SUBJECTS),
            2 => $this->pick(self::TOPIC_SUBJECTS).' - '.$this->pick(self::TOPIC_TAILS),
            3 => $this->pick(self::TOPIC_QUESTIONS),
            4 => $this->pick(self::TOPIC_SUBJECTS),
            default => $this->pick(self::TOPIC_OPENERS).' '.$this->pick(self::TOPIC_SUBJECTS).' ('.$this->pick(self::TOPIC_TAILS).')',
        };
    }

    private function openingPost(): string
    {
        $body = $this->pick(self::OPENINGS)."\n\n".$this->paragraph();

        if (random_int(1, 4) > 3) {
            $body .= "\n\n[code]\n".$this->pick(self::CODE_SNIPPETS)."\n[/code]";
        }
        if (random_int(1, 5) > 4) {
            $body .= "\n\n".$this->pick(self::CLOSERS);
        }

        return $body;
    }

    private function reply(): string
    {
        $body = '';

        if (random_int(1, 4) > 3) {
            $body .= '[quote]'.$this->pick(self::QUOTABLES)."[/quote]\n\n";
        }

        $body .= $this->paragraph();

        if (random_int(1, 6) > 5) {
            $body .= ' '.$this->pick(self::SMILIES);
        }

        return $body;
    }

    private function paragraph(): string
    {
        $sentences = [];
        foreach (range(1, random_int(1, 5)) as $ignored) {
            $sentence = $this->pick(self::SENTENCES);
            $sentences[] = match (random_int(1, 8)) {
                1 => preg_replace('/^(\w+)/', '[b]$1[/b]', $sentence),
                2 => preg_replace('/(\w+)\.$/', '[i]$1[/i].', $sentence),
                default => $sentence,
            };
        }

        return implode(' ', $sentences);
    }

    // ------------------------------------------------------------------
    // Word lists
    // ------------------------------------------------------------------

    private const HANDLE_ADJECTIVES = [
        'Super', 'Mega', 'Hyper', 'Dark', 'Neo', 'Retro', 'Pixel', 'Turbo', 'Cosmic',
        'Silent', 'Golden', 'Crimson', 'Frozen', 'Electric', 'Wild', 'Lucky', 'Quantum',
        'Rusty', 'Velvet', 'Iron', 'Shadow', 'Chrome', 'Glitch', 'Analog', 'Static',
    ];

    private const HANDLE_NOUNS = [
        'Koopa', 'Goomba', 'Yoshi', 'Ninja', 'Wizard', 'Falcon', 'Comet', 'Raven',
        'Sprite', 'Cartridge', 'Emulator', 'Joystick', 'Modem', 'Router', 'Pixel',
        'Samurai', 'Gremlin', 'Phantom', 'Otter', 'Badger', 'Hedgehog', 'Dragon',
        'Wombat', 'Penguin', 'Mantis', 'Beetle', 'Marmot', 'Lynx', 'Heron',
    ];

    private const HANDLE_SUFFIXES = ['X', 'Z', '64', '2000', 'XL', 'Jr', 'Prime', 'HD', 'EX', 'Zero'];

    private const TITLES = [
        'Local cartridge enthusiast',
        'Probably asleep',
        'Certified thread necromancer',
        'Ask me about my emulator',
        'Here since the beginning',
        'Professional lurker',
        '[i]retired[/i]',
        'Will fix your table markup',
    ];

    private const SIGNATURES = [
        'Currently playing: something with too many menus.',
        '[i]There is no signature here. Move along.[/i]',
        'If you can read this, my layout is broken again.',
        'Posting from a machine older than this board.',
        '[b]Backups[/b] are not optional.',
    ];

    private const BIOS = [
        'Been reading this place for years, finally made an account.',
        'I mostly post about old hardware and occasionally about food.',
        'Sprite artist, very slow. Ask me for commissions and wait a month.',
        'I am here for the arguments about emulator accuracy.',
        'Long-time lurker, occasional poster, permanent nuisance.',
    ];

    private const LOCATIONS = [
        'Somewhere cold', 'The Netherlands', 'Ohio', 'A basement', 'Melbourne',
        'North of England', 'Toronto', 'Behind you', 'Sao Paulo', 'Kyoto',
        'A very slow train', 'Scotland', 'Berlin', 'The moon (pending)',
    ];

    private const TIMEZONES = [
        'UTC', 'Europe/London', 'Europe/Berlin', 'Europe/Amsterdam', 'America/New_York',
        'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Sao_Paulo',
        'Asia/Tokyo', 'Asia/Kolkata', 'Australia/Sydney', 'Pacific/Auckland',
    ];

    private const ICONS = ['icon1', 'icon2', 'icon3', 'icon4', 'icon5', 'icon13', 'icon14'];

    private const TOPIC_OPENERS = [
        'Thoughts on', 'Anyone else having trouble with', 'Finally finished',
        'A closer look at', 'Help with', 'The definitive thread about',
        'Unpopular opinion about', 'Show off your', 'Weekly discussion:',
    ];

    private const TOPIC_SUBJECTS = [
        'the new layout engine', 'save state compatibility', 'CRT filters',
        'palette swaps', 'my terrible sprite work', 'controller latency',
        'the post rank ladder', 'old forum software', 'floppy disk rot',
        'that one level everybody hates', 'chiptune trackers', 'cartridge batteries',
        'scanline shaders', 'romhacking tools', 'the board redesign',
        'avatar size limits', 'signature etiquette', 'thread necromancy',
    ];

    private const TOPIC_TAILS = [
        'part two', 'a retrospective', 'please read before posting', 'now with pictures',
        'solved', 'still broken', 'an update', 'my two cents', 'long post sorry',
    ];

    private const TOPIC_QUESTIONS = [
        'Is it just me or is this harder than it used to be?',
        'What is everyone working on this month?',
        'Why does nobody talk about this anymore?',
        'Best way to back up an old drive?',
        'Does anyone still have the original files?',
        'How do I get this running on modern hardware?',
        'What happened to the old thread about this?',
    ];

    private const OPENINGS = [
        'So I have been poking at this for a couple of weeks and I think I finally understand what is going on.',
        'Long post ahead, sorry, but I wanted to write this down before I forget it.',
        'Quick question that I could not find an answer to anywhere else.',
        'This has probably been asked before, in which case point me at the old thread.',
        'I put together some notes on this because the documentation is basically nonexistent.',
        'Not sure if this belongs here, move it if it does not.',
    ];

    private const SENTENCES = [
        'The behaviour changed somewhere between versions and nobody wrote it down.',
        'I tested it on three different machines and got three different results.',
        'It turns out the timing is off by exactly one frame, which explains everything.',
        'You can work around it, but the workaround is worse than the bug.',
        'Documentation says one thing and the actual implementation does another.',
        'It has worked this way for twenty years so I assume it is deliberate at this point.',
        'Someone smarter than me will probably have a better explanation.',
        'I am not convinced this is a bug so much as a very confusing feature.',
        'The old version handled this fine, which is what makes it frustrating.',
        'If anyone has a copy of the original files I would love to compare.',
        'Took me an embarrassingly long time to notice the obvious answer.',
        'Screenshots attached, though they do not really show the problem.',
        'I have a patch for it but I want a second opinion before posting it.',
        'That matches what I remember, though my memory is not what it was.',
        'Worth noting that this only happens on the PAL version.',
        'It is a small thing but it bothers me every single time.',
    ];

    private const CLOSERS = [
        'Anyway, thoughts welcome.',
        'Will update this post if I work anything else out.',
        'Sorry for the wall of text.',
        'Happy to be told I am completely wrong about this.',
    ];

    private const QUOTABLES = [
        'It has worked this way for twenty years so I assume it is deliberate.',
        'I tested it on three different machines and got three different results.',
        'The old version handled this fine.',
        'Someone smarter than me will probably have a better explanation.',
    ];

    private const CODE_SNIPPETS = [
        "LDA #\$00\nSTA \$2000\nRTS",
        "for (i = 0; i < 256; i++) {\n    palette[i] = read_byte(base + i);\n}",
        "SELECT * FROM posts WHERE thread = 1 ORDER BY date;",
        "$ ./configure --prefix=/usr/local\n$ make && make install",
    ];

    private const SMILIES = [':)', ':(', ':D', ';)', ':P', ':O', ':/'];

    private const MESSAGE_SUBJECTS = [
        'About your post in the layout thread',
        'That file you mentioned',
        'Re: the thing',
        'Question about your avatar',
        'Sorry for the late reply',
        'Are you still around?',
        'Following up',
        'Thanks for the help earlier',
    ];

    private const EVENTS = [
        'Board anniversary',
        'Scheduled maintenance window',
        'Community game night',
        'Sprite contest deadline',
        'Staff meeting',
        'Server migration',
        'Monthly screenshot thread goes up',
    ];
}
