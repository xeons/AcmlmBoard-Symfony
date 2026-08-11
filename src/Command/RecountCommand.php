<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ForumRepository;
use App\Repository\PostLayoutRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds every denormalised counter from the underlying rows.
 *
 * The original had no equivalent, which is why long-lived AcmlmBoards drifted:
 * post counts that never went down when posts were deleted, forums claiming more
 * threads than they held, threads whose reply count did not match their posts.
 * Safe to run at any time; it only ever writes derived values.
 */
#[AsCommand(
    name: 'app:board:recount',
    description: 'Rebuilds post counts, reply counts and forum totals from the actual rows.',
)]
final class RecountCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly ForumRepository $forums,
        private readonly PostLayoutRepository $layouts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('prune-layouts', null, InputOption::VALUE_NONE, 'Also delete unreferenced post layouts.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('User post counts');
        $users = $this->connection->executeStatement(
            'UPDATE users u SET u.posts = (
                SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id
             )',
        );
        $io->writeln(\sprintf('  %d user rows updated.', $users));

        $io->section('Thread reply counts and last posts');
        $threads = $this->connection->executeStatement(
            'UPDATE threads t SET
                t.replies = GREATEST(0, (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id) - 1),
                t.last_post_at = COALESCE(
                    (SELECT MAX(p.created_at) FROM posts p WHERE p.thread_id = t.id),
                    t.created_at
                )',
        );
        $io->writeln(\sprintf('  %d thread rows updated.', $threads));

        // The last poster needs the row with the highest id, which a single
        // correlated UPDATE cannot express portably.
        $this->connection->executeStatement(
            'UPDATE threads t
             JOIN (
                SELECT p.thread_id, p.author_id
                FROM posts p
                JOIN (SELECT thread_id, MAX(id) AS max_id FROM posts GROUP BY thread_id) m
                  ON m.thread_id = p.thread_id AND m.max_id = p.id
             ) last ON last.thread_id = t.id
             SET t.last_poster_id = last.author_id',
        );

        $io->section('Forum totals');
        $count = 0;
        foreach ($this->forums->findAll() as $forum) {
            $this->forums->recount($forum);
            ++$count;
        }
        $this->em->flush();
        $io->writeln(\sprintf('  %d forums recounted.', $count));

        $io->section('Threads with no posts');
        $orphans = $this->connection->executeStatement(
            'DELETE FROM threads WHERE id NOT IN (SELECT DISTINCT thread_id FROM posts)',
        );
        $io->writeln(\sprintf('  %d empty threads removed.', $orphans));

        if ($input->getOption('prune-layouts')) {
            $io->section('Orphaned post layouts');
            $io->writeln(\sprintf('  %d layout rows removed.', $this->layouts->deleteOrphans()));
        }

        $io->success('Recount complete.');

        return Command::SUCCESS;
    }
}
