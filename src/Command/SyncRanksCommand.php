<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Rank;
use App\Entity\RankSet;
use App\Repository\RankSetRepository;
use App\Service\RankLadderCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Brings the rank ladders on an existing board in line with config/ranks.json.
 *
 * The fixtures only ever run on an empty database, so a board that is already
 * carrying members and posts needs this instead. Sets are matched by name and
 * their rungs replaced wholesale; the set row itself is kept so that every member
 * who has chosen that ladder keeps their choice. A set that is not in the JSON is
 * left completely alone, which is what makes this safe to run on a board whose
 * administrator has added ladders of their own.
 */
#[AsCommand(
    name: 'app:ranks:sync',
    description: 'Replaces the rungs of the shipped rank ladders from config/ranks.json.',
)]
final class SyncRanksCommand extends Command
{
    public function __construct(
        private readonly RankLadderCatalog $catalog,
        private readonly RankSetRepository $rankSets,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $definitions = $this->catalog->sets();
        if ([] === $definitions) {
            $io->error('config/ranks.json is missing or empty; nothing to sync.');

            return Command::FAILURE;
        }

        $rows = [];

        foreach ($definitions as $definition) {
            $set = $this->rankSets->findOneBy(['name' => $definition['name']]);
            $status = 'updated';

            if (null === $set) {
                $set = new RankSet($definition['name']);
                $this->em->persist($set);
                $status = 'created';
            }

            $before = \count($set->getRanks());

            $set->setPosition($definition['position']);
            $set->setPercentileBased($definition['percentileBased']);

            // orphanRemoval on RankSet::$ranks turns this into DELETEs for the
            // old rungs. Nothing points at a Rank row, so none of it cascades
            // into member data.
            foreach ($set->getRanks()->toArray() as $existing) {
                $set->getRanks()->removeElement($existing);
            }

            foreach ($definition['ranks'] as $rung) {
                $rank = new Rank($set, $rung['minPosts'], $rung['label']);
                $rank->setPercentile($rung['percentile'] ?? null);
                $set->addRank($rank);
                $this->em->persist($rank);
            }

            $rows[] = [$definition['name'], $status, $before, \count($definition['ranks'])];
        }

        $io->table(['Rank set', 'Action', 'Rungs before', 'Rungs after'], $rows);

        if ($dryRun) {
            $io->warning('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $this->em->flush();

        $io->success('Rank ladders synced.');
        $io->note('Percentile thresholds stay unreachable until "app:board:maintenance" recomputes them.');

        return Command::SUCCESS;
    }
}
