<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ForumModerator;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Enum\PowerLevel;
use App\Repository\ForumRepository;
use App\Service\PostManager;
use App\Service\RegistrationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The fixed cast of members, forums and threads every functional test runs against.
 *
 * Building it costs a schema create plus the reference fixtures, which is far too
 * slow to repeat before each of a hundred tests. So it is built once per process and
 * then captured as a row-level snapshot; resetting is a truncate and a bulk reinsert,
 * which restores identical state - including auto-increment counters, so entity ids
 * are stable and tests may refer to them directly.
 *
 * Tests get a pristine board each time rather than sharing one. That matters more
 * here than the seconds it costs: half of what is under test is moderation and
 * banning, and a test that bans someone must not decide whether the next test passes.
 */
final class TestWorld
{
    public const PASSWORD = 'test-password-123';

    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $snapshot = null;

    /** @var list<string> */
    private static array $tables = [];

    /** Named ids captured at build time, so tests can address the cast by name. */
    private static array $ids = [];

    /**
     * Builds the world if this process has not already done so, then restores it.
     */
    public static function reset(KernelInterface $kernel): void
    {
        if (null === self::$snapshot) {
            self::build($kernel);
            self::capture(self::connection($kernel));

            return;
        }

        self::restore(self::connection($kernel));
    }

    /** The id of a seeded record, e.g. id('user', 'Member'). */
    public static function id(string $type, string $name): int
    {
        if (!isset(self::$ids[$type][$name])) {
            throw new \LogicException(\sprintf('Nothing named "%s" was seeded as a %s.', $name, $type));
        }

        return self::$ids[$type][$name];
    }

    // ------------------------------------------------------------------
    // Building
    // ------------------------------------------------------------------

    private static function build(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer()->get('test.service_container');
        $em = $container->get(EntityManagerInterface::class);

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        // Reference data - schemes, layouts, ranks, shop, starter forums, config.
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->run(
            new ArrayInput([
                'command' => 'doctrine:fixtures:load',
                '--append' => true,
                '--no-interaction' => true,
            ]),
            new NullOutput(),
        );

        $em->clear();
        self::seed($container);
    }

    private static function seed(ContainerInterface $container): void
    {
        $em = $container->get(EntityManagerInterface::class);
        $registration = $container->get(RegistrationService::class);
        $posts = $container->get(PostManager::class);
        $forums = $container->get(ForumRepository::class);

        // ---------------------------------------------------------- members
        // Registered a good while back, so level, rank and RPG stats are non-zero
        // and the code paths that divide by account age actually execute.
        $cast = [
            'Owner' => PowerLevel::Owner,
            'Admin' => PowerLevel::Administrator,
            'Mod' => PowerLevel::Moderator,
            'LocalMod' => PowerLevel::LocalModerator,
            'Member' => PowerLevel::Member,
            'Other' => PowerLevel::Member,
            'Banned' => PowerLevel::Banned,
        ];

        $users = [];
        foreach ($cast as $name => $power) {
            $user = $registration->createUser($name, strtolower($name).'@example.test', self::PASSWORD, $power);
            $user->setRegisteredAt(new \DateTimeImmutable('-400 days'));
            $user->setPosts(0);
            $user->setLastActivityAt(new \DateTimeImmutable('-1 hour'));
            $users[$name] = $user;
            self::$ids['user'][$name] = (int) $user->getId();
        }

        $em->flush();

        // ---------------------------------------------------------- forums
        $byTitle = [];
        foreach ($forums->findAll() as $forum) {
            $byTitle[$forum->getTitle()] = $forum;
            self::$ids['forum'][$forum->getTitle()] = (int) $forum->getId();
        }

        $general = $byTitle['General discussion'];
        $staffOnly = $byTitle['Staff discussion'];

        // LocalMod moderates exactly one forum - the distinction between a local and
        // a global moderator is the point of several access-control tests.
        $em->persist(new ForumModerator($general, $users['LocalMod']));

        // A forum nobody below administrator may post in, to exercise the
        // minPowerThread/minPowerReply branches independently of readability.
        $byTitle['Announcements']->setMinPowerThread(3);
        $byTitle['Announcements']->setMinPowerReply(3);

        $em->flush();

        // ---------------------------------------------------------- threads
        $open = $posts->createThread($general, $users['Member'], 'A perfectly ordinary thread', 'The opening post.', '203.0.113.10');
        $posts->reply($open, $users['Other'], 'A reply from somebody else.', '203.0.113.11');
        self::$ids['thread']['open'] = (int) $open->getId();

        $closed = $posts->createThread($general, $users['Member'], 'A closed thread', 'Nothing more to say.', '203.0.113.10');
        $closed->setClosed(true);
        self::$ids['thread']['closed'] = (int) $closed->getId();

        $locked = $posts->createThread($general, $users['Member'], 'A locked thread', 'Admins only beyond this point.', '203.0.113.10');
        $locked->setLocked(true);
        self::$ids['thread']['locked'] = (int) $locked->getId();

        $secret = $posts->createThread($staffOnly, $users['Admin'], 'Staff business', 'Not for the public.', '203.0.113.1');
        self::$ids['thread']['staff'] = (int) $secret->getId();

        $em->flush();

        // Post ids, addressed by role rather than number.
        $conn = $em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT id, thread_id FROM posts ORDER BY id',
        );
        foreach ($rows as $row) {
            if ((int) $row['thread_id'] === self::$ids['thread']['open']) {
                self::$ids['post'][isset(self::$ids['post']['first']) ? 'reply' : 'first'] = (int) $row['id'];
            }
            if ((int) $row['thread_id'] === self::$ids['thread']['staff']) {
                self::$ids['post']['staff'] = (int) $row['id'];
            }
        }

        // ---------------------------------------------------------- messages
        $unread = new PrivateMessage($users['Admin'], $users['Member'], 'A private word', 'Please read this.');
        $em->persist($unread);

        // Deliberately marked read: an unread *system* message blocks all posting,
        // which would make every posting test fail for the wrong reason.
        $system = new PrivateMessage(null, $users['Member'], 'Board notice', 'You have been warned.');
        $system->setSystem(true);
        $system->markRead();
        $em->persist($system);

        $em->flush();

        self::$ids['message']['unread'] = (int) $unread->getId();
        self::$ids['message']['system'] = (int) $system->getId();

        // Post counts are set last, on purpose. Seeding the threads above credits
        // their authors, so assigning these first would leave Member on 503 rather
        // than 500 and make every rank and level assertion read like a typo.
        $users['Member']->setPosts(500);
        $users['Other']->setPosts(120);
        $em->flush();

        $em->clear();
    }

    // ------------------------------------------------------------------
    // Snapshot and restore
    // ------------------------------------------------------------------

    private static function capture(Connection $conn): void
    {
        self::$tables = $conn->createSchemaManager()->listTableNames();

        $snapshot = [];
        foreach (self::$tables as $table) {
            $snapshot[$table] = $conn->fetchAllAssociative('SELECT * FROM '.$conn->quoteIdentifier($table));
        }

        self::$snapshot = $snapshot;
    }

    private static function restore(Connection $conn): void
    {
        // TRUNCATE resets AUTO_INCREMENT, which DELETE does not - without that the
        // ids captured at build time would drift out from under the tests.
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (self::$tables as $table) {
                $conn->executeStatement('TRUNCATE TABLE '.$conn->quoteIdentifier($table));
            }

            foreach (self::$snapshot ?? [] as $table => $rows) {
                if ([] === $rows) {
                    continue;
                }

                $columns = array_keys($rows[0]);
                $quoted = array_map($conn->quoteIdentifier(...), $columns);
                $placeholders = '('.implode(',', array_fill(0, \count($columns), '?')).')';

                // One statement per table; the seeded board is small enough that
                // chunking buys nothing.
                $values = [];
                $parameters = [];
                foreach ($rows as $row) {
                    $values[] = $placeholders;
                    foreach ($columns as $column) {
                        $parameters[] = $row[$column];
                    }
                }

                $conn->executeStatement(
                    'INSERT INTO '.$conn->quoteIdentifier($table)
                    .' ('.implode(',', $quoted).') VALUES '.implode(',', $values),
                    $parameters,
                );
            }
        } finally {
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private static function connection(KernelInterface $kernel): Connection
    {
        return $kernel->getContainer()->get('test.service_container')
            ->get(EntityManagerInterface::class)
            ->getConnection();
    }
}
