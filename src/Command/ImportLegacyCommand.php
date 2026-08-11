<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Category;
use App\Entity\Forum;
use App\Entity\Post;
use App\Entity\PostLayout;
use App\Entity\Thread;
use App\Entity\User;
use App\Enum\PowerLevel;
use App\Enum\Sex;
use App\Enum\SignatureDisplay;
use App\Enum\SignatureSeparator;
use App\Repository\ColorSchemeRepository;
use App\Repository\RankSetRepository;
use App\Repository\ThreadLayoutRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports an existing AcmlmBoard 1.a3 database.
 *
 * The URL structure and schema changed, so this maps the old tables onto the new
 * ones. Notable translations:
 *
 *   - `users.password` holds a raw md5; it is copied across with
 *     passwordLegacyMd5 set, so members log in once with their old password and are
 *     transparently rehashed. See LegacyMd5PasswordHasher.
 *   - `posts` and `posts_text` are joined into one row.
 *   - `posts_text.tagval`, a chr(255)-delimited blob, is parsed into JSON.
 *   - integer unix timestamps become real datetimes.
 *   - the AIM, ICQ and imood columns are dropped; those services are gone.
 *
 * Post bodies are copied verbatim and *not* sanitised at import: sanitising happens
 * on render, so nothing is lost and old layouts can still be repaired by hand.
 */
#[AsCommand(
    name: 'app:board:import-legacy',
    description: 'Imports users, forums, threads and posts from an AcmlmBoard 1.a3 database.',
)]
final class ImportLegacyCommand extends Command
{
    private const BATCH = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ColorSchemeRepository $schemes,
        private readonly ThreadLayoutRepository $layouts,
        private readonly RankSetRepository $rankSets,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'DBAL URL of the legacy database')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be imported, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dsn = (string) $input->getOption('dsn');
        $dryRun = (bool) $input->getOption('dry-run');

        if ('' === $dsn) {
            $io->error('Pass the legacy database as --dsn="mysql://user:pass@host/acmlmboard".');

            return Command::FAILURE;
        }

        $legacy = DriverManager::getConnection(['url' => $dsn]);

        $io->title('Importing legacy AcmlmBoard data');
        if ($dryRun) {
            $io->note('Dry run: nothing will be written.');
        }

        $userMap = $this->importUsers($legacy, $io, $dryRun);
        $categoryMap = $this->importCategories($legacy, $io, $dryRun);
        $forumMap = $this->importForums($legacy, $io, $dryRun, $categoryMap);
        $threadMap = $this->importThreads($legacy, $io, $dryRun, $forumMap, $userMap);
        $this->importPosts($legacy, $io, $dryRun, $threadMap, $userMap);

        $io->success($dryRun
            ? 'Dry run complete.'
            : 'Import complete. Run app:board:recount next to rebuild the counters.');

        return Command::SUCCESS;
    }

    /** @return array<int, int> legacy user id => new user id */
    private function importUsers(Connection $legacy, SymfonyStyle $io, bool $dryRun): array
    {
        $io->section('Users');

        $scheme = $this->schemes->findDefault();
        $layout = $this->layouts->findDefault();
        $rankSet = $this->rankSets->findAllOrdered()[0] ?? null;

        $map = [];
        $seen = [];
        $count = 0;

        foreach ($legacy->iterateAssociative('SELECT * FROM users ORDER BY id') as $row) {
            $name = trim((string) $row['name']);
            if ('' === $name) {
                continue;
            }

            // The legacy schema had no unique index on the canonical name, so
            // near-duplicate accounts ("Bob" and "B ob") could coexist. Suffix the
            // later one rather than aborting the whole import.
            $canonical = User::canonicalizeUsername($name);
            if (isset($seen[$canonical])) {
                $name .= '_'.$row['id'];
                $canonical = User::canonicalizeUsername($name);
            }
            $seen[$canonical] = true;

            $user = new User();
            $user->setUsername($name);
            $user->setEmail($this->nullIfBlank($row['email'] ?? null));
            $user->setPassword((string) ($row['password'] ?? ''));
            $user->setPasswordLegacyMd5(true);
            $user->setPosts((int) ($row['posts'] ?? 0));
            $user->setRegisteredAt($this->toDate($row['regdate'] ?? 0) ?? new \DateTimeImmutable());
            $user->setLastActivityAt($this->toDate($row['lastactivity'] ?? null));
            $user->setLastPostAt($this->toDate($row['lastposttime'] ?? null));
            $user->setLastIp($this->nullIfBlank($row['lastip'] ?? null));
            $user->setPowerLevel($this->toPowerLevel((int) ($row['powerlevel'] ?? 0)));
            $user->setSex(Sex::tryFrom((int) ($row['sex'] ?? 2)) ?? Sex::Undisclosed);
            $user->setTitle($this->nullIfBlank($row['title'] ?? null));
            $user->setTitleOption((int) ($row['titleoption'] ?? 1));
            $user->setPicture($this->nullIfBlank($row['picture'] ?? null));
            $user->setMiniPic($this->nullIfBlank($row['minipic'] ?? null));
            $user->setPostBackground($this->nullIfBlank($row['postbg'] ?? null));
            $user->setPostHeader($this->nullIfBlank($row['postheader'] ?? null));
            $user->setSignature($this->nullIfBlank($row['signature'] ?? null));
            $user->setBio($this->nullIfBlank($row['bio'] ?? null));
            $user->setRealName($this->nullIfBlank($row['realname'] ?? null));
            $user->setLocation($this->nullIfBlank($row['location'] ?? null));
            $user->setBirthday($this->toDate($row['birthday'] ?? null));
            $user->setHomepageUrl($this->nullIfBlank($row['homepageurl'] ?? null));
            $user->setHomepageName($this->nullIfBlank($row['homepagename'] ?? null));
            $user->setEmailPublic(1 === (int) ($row['publicemail'] ?? 1));
            $user->setPostsPerPage(max(5, min(100, (int) ($row['postsperpage'] ?? 20))));
            $user->setThreadsPerPage(max(10, min(200, (int) ($row['threadsperpage'] ?? 50))));
            // The legacy column was a float number of hours from board time. An
            // offset cannot identify a zone - it carries no daylight-saving rules -
            // so it maps to a representative region zone as a starting point, and the
            // profile form offers to detect the real one from the member's browser.
            $user->setTimezone($this->offsetToTimezone((float) ($row['timezone'] ?? 0)));
            $user->setSignatureDisplay(SignatureDisplay::tryFrom((int) ($row['viewsig'] ?? 1)) ?? SignatureDisplay::AsPosted);
            $user->setSignatureSeparator(SignatureSeparator::tryFrom((int) ($row['signsep'] ?? 0)) ?? SignatureSeparator::Dashes);
            $user->setPostToolbar(1 === (int) ($row['posttool'] ?? 1));
            $user->setMarkReadOutsideMenu(1 === (int) ($row['oldstylemark'] ?? 0));
            $user->setColorScheme($scheme);
            $user->setThreadLayout($layout);
            $user->setRankSet($rankSet);

            if (!$dryRun) {
                $this->em->persist($user);
                if (0 === ++$count % self::BATCH) {
                    $this->em->flush();
                }
            } else {
                ++$count;
            }

            $map[(int) $row['id']] = $user;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(\sprintf('  %d users.', $count));

        return $map;
    }

    /** @return array<int, Category> */
    private function importCategories(Connection $legacy, SymfonyStyle $io, bool $dryRun): array
    {
        $io->section('Categories');
        $map = [];

        foreach ($legacy->iterateAssociative('SELECT * FROM categories ORDER BY id') as $row) {
            $category = new Category();
            $category->setName((string) $row['name']);
            $category->setMinPower((int) ($row['minpower'] ?? 0));
            $category->setPosition((int) $row['id']);

            if (!$dryRun) {
                $this->em->persist($category);
            }

            $map[(int) $row['id']] = $category;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(\sprintf('  %d categories.', \count($map)));

        return $map;
    }

    /**
     * @param array<int, Category> $categories
     *
     * @return array<int, Forum>
     */
    private function importForums(Connection $legacy, SymfonyStyle $io, bool $dryRun, array $categories): array
    {
        $io->section('Forums');
        $map = [];

        // Any forum whose category vanished lands in a synthetic one rather than
        // being silently dropped.
        $fallback = null;

        foreach ($legacy->iterateAssociative('SELECT * FROM forums ORDER BY forder, id') as $row) {
            $category = $categories[(int) ($row['catid'] ?? 0)] ?? null;

            if (null === $category) {
                $fallback ??= (static function () use ($dryRun): Category {
                    $c = new Category();
                    $c->setName('Uncategorised');

                    return $c;
                })();
                if (!$dryRun && null === $fallback->getId()) {
                    $this->em->persist($fallback);
                    $this->em->flush();
                }
                $category = $fallback;
            }

            $forum = new Forum();
            $forum->setTitle((string) ($row['title'] ?? 'Untitled'));
            $forum->setDescription($this->nullIfBlank($row['description'] ?? null));
            $forum->setCategory($category);
            $forum->setMinPower((int) ($row['minpower'] ?? 0));
            $forum->setMinPowerThread((int) ($row['minpowerthread'] ?? 0));
            $forum->setMinPowerReply((int) ($row['minpowerreply'] ?? 0));
            $forum->setPosition((int) ($row['forder'] ?? 0));

            if (!$dryRun) {
                $this->em->persist($forum);
            }

            $map[(int) $row['id']] = $forum;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(\sprintf('  %d forums.', \count($map)));

        return $map;
    }

    /**
     * @param array<int, Forum> $forums
     * @param array<int, User>  $users
     *
     * @return array<int, Thread>
     */
    private function importThreads(Connection $legacy, SymfonyStyle $io, bool $dryRun, array $forums, array $users): array
    {
        $io->section('Threads');
        $map = [];
        $count = 0;

        foreach ($legacy->iterateAssociative('SELECT * FROM threads ORDER BY id') as $row) {
            $forum = $forums[(int) ($row['forum'] ?? 0)] ?? null;
            if (null === $forum) {
                continue;
            }

            $thread = new Thread(
                $forum,
                $users[(int) ($row['user'] ?? 0)] ?? null,
                mb_substr((string) ($row['title'] ?? 'Untitled'), 0, 100),
            );
            $thread->setViews((int) ($row['views'] ?? 0));
            $thread->setReplies((int) ($row['replies'] ?? 0));
            $thread->setClosed((bool) ($row['closed'] ?? 0));
            $thread->setSticky((bool) ($row['sticky'] ?? 0));
            $thread->setLocked((bool) ($row['locked'] ?? 0));
            $thread->setLastPoster($users[(int) ($row['lastposter'] ?? 0)] ?? null);

            if (null !== $last = $this->toDate($row['lastpostdate'] ?? null)) {
                $thread->setLastPostAt($last);
            }

            // `threads.icon` held a full URL in the original, which is exactly the
            // stored-XSS vector PostIconType exists to close. Icons are dropped on
            // import; they can be reassigned from the shipped set.
            $thread->setIcon(null);

            if (!$dryRun) {
                $this->em->persist($thread);
                if (0 === ++$count % self::BATCH) {
                    $this->em->flush();
                }
            } else {
                ++$count;
            }

            $map[(int) $row['id']] = $thread;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(\sprintf('  %d threads.', $count));

        return $map;
    }

    /**
     * @param array<int, Thread> $threads
     * @param array<int, User>   $users
     */
    private function importPosts(Connection $legacy, SymfonyStyle $io, bool $dryRun, array $threads, array $users): void
    {
        $io->section('Posts');

        /** @var array<string, PostLayout> $layoutCache */
        $layoutCache = [];
        $count = 0;

        $sql = 'SELECT p.*, t.text, t.headtext, t.signtext, t.tagval, t.edited
                FROM posts p LEFT JOIN posts_text t ON t.pid = p.id
                ORDER BY p.id';

        foreach ($legacy->iterateAssociative($sql) as $row) {
            $thread = $threads[(int) ($row['thread'] ?? 0)] ?? null;
            if (null === $thread) {
                continue;
            }

            $post = new Post(
                $thread,
                $users[(int) ($row['user'] ?? 0)] ?? null,
                (string) ($row['text'] ?? ''),
            );
            $post->setCreatedAt($this->toDate($row['date'] ?? null) ?? new \DateTimeImmutable());
            $post->setIp($this->nullIfBlank($row['ip'] ?? null));
            $post->setAuthorPostNumber((int) ($row['num'] ?? 0));
            $post->setTagValues($this->parseTagValues((string) ($row['tagval'] ?? '')));

            $post->setHeaderLayout($this->layoutFor($row['headtext'] ?? null, $layoutCache, $dryRun));
            $post->setSignatureLayout($this->layoutFor($row['signtext'] ?? null, $layoutCache, $dryRun));

            if (!$dryRun) {
                $this->em->persist($post);
                if (0 === ++$count % self::BATCH) {
                    $this->em->flush();
                }
            } else {
                ++$count;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(\sprintf('  %d posts.', $count));
    }

    /** @param array<string, PostLayout> $cache */
    private function layoutFor(?string $body, array &$cache, bool $dryRun): ?PostLayout
    {
        if (null === $body || '' === trim($body)) {
            return null;
        }

        $hash = PostLayout::hash($body);

        if (!isset($cache[$hash])) {
            $layout = new PostLayout($body);
            if (!$dryRun) {
                $this->em->persist($layout);
            }
            $cache[$hash] = $layout;
        }

        return $cache[$hash];
    }

    /**
     * Parses the legacy `tagval` blob.
     *
     * The format was pairs of chr(255).chr(255)-delimited token/value runs, which
     * means any signature containing that byte sequence corrupted the record. Values
     * that fail to parse are dropped rather than guessed at.
     *
     * @return array<string, string>
     */
    private function parseTagValues(string $raw): array
    {
        if ('' === $raw) {
            return [];
        }

        $delimiter = \chr(255).\chr(255);
        $parts = array_values(array_filter(explode($delimiter, $raw), static fn (string $p): bool => '' !== $p));

        $values = [];
        for ($i = 0; $i + 1 < \count($parts); $i += 2) {
            $token = $parts[$i];
            // Only accept things that look like the &token& vocabulary.
            if (preg_match('/^&[a-z0-9]+&$/i', $token)) {
                $values[$token] = $parts[$i + 1];
            }
        }

        return $values;
    }

    /**
     * A representative region zone for each legacy hour offset.
     *
     * The obvious encoding is Etc/GMT+/-N, and it is wrong here. Those identifiers
     * construct fine and keep the right time, so they look correct - but they live in
     * PHP's backward-compatible set rather than DateTimeZone::ALL. Assert\Timezone
     * validates against ALL and the timezone selector is built from it, so an
     * imported member carrying one could not save their profile at all, and their own
     * zone would not appear in the list.
     *
     * Naming a region is a guess about *where* somebody is, and one that will be
     * wrong for part of the year wherever the guess and the member disagree about
     * daylight saving. It is still the better trade: the value is valid, it is
     * selectable, and the profile form offers to correct it from the browser on their
     * first visit. Half-hour offsets are mapped too, since India alone accounts for a
     * good number of them.
     *
     * @param float $hours offset from UTC, as the legacy column stored it
     */
    private function offsetToTimezone(float $hours): string
    {
        $minutes = (int) round($hours * 60);

        return match ($minutes) {
            -660 => 'Pacific/Midway',
            -600 => 'Pacific/Honolulu',
            -540 => 'America/Anchorage',
            -480 => 'America/Los_Angeles',
            -420 => 'America/Denver',
            -360 => 'America/Chicago',
            -300 => 'America/New_York',
            -240 => 'America/Halifax',
            -210 => 'America/St_Johns',
            -180 => 'America/Sao_Paulo',
            -120 => 'America/Noronha',
            -60 => 'Atlantic/Azores',
            60 => 'Europe/Berlin',
            120 => 'Europe/Athens',
            180 => 'Europe/Moscow',
            210 => 'Asia/Tehran',
            240 => 'Asia/Dubai',
            300 => 'Asia/Karachi',
            330 => 'Asia/Kolkata',
            345 => 'Asia/Kathmandu',
            360 => 'Asia/Dhaka',
            420 => 'Asia/Bangkok',
            480 => 'Asia/Shanghai',
            540 => 'Asia/Tokyo',
            570 => 'Australia/Adelaide',
            600 => 'Australia/Brisbane',
            660 => 'Pacific/Guadalcanal',
            720 => 'Pacific/Auckland',
            // Zero, and anything that is not a recognised offset at all.
            default => 'UTC',
        };
    }

    private function toPowerLevel(int $legacy): PowerLevel
    {
        // Levels 4 and 5 were assigned by lib/layout.php on every request based on
        // board config, not stored intent, so imported accounts are capped at admin.
        return PowerLevel::tryFrom(min(3, max(-1, $legacy))) ?? PowerLevel::Member;
    }

    private function toDate(mixed $timestamp): ?\DateTimeImmutable
    {
        $value = (int) $timestamp;

        return $value > 0 ? (new \DateTimeImmutable())->setTimestamp($value) : null;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return '' === $string ? null : $string;
    }
}
