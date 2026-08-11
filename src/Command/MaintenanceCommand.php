<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ForumBanRepository;
use App\Repository\IpBanRepository;
use App\Repository\PendingRegistrationRepository;
use App\Repository\SoftBanRepository;
use App\Service\BoardStatsService;
use App\Service\OnlineTracker;
use App\Service\RankPercentileUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Periodic housekeeping.
 *
 * Every task here was something the original board did *on every page request*:
 * expiring bans, recalculating percentile ranks, writing the daily stats row, and
 * pruning the guest table. Run this from cron every few minutes.
 */
#[AsCommand(
    name: 'app:board:maintenance',
    description: 'Expires bans, prunes stale sessions and refreshes board statistics.',
)]
final class MaintenanceCommand extends Command
{
    public function __construct(
        private readonly IpBanRepository $ipBans,
        private readonly SoftBanRepository $softBans,
        private readonly ForumBanRepository $forumBans,
        private readonly PendingRegistrationRepository $pending,
        private readonly OnlineTracker $online,
        private readonly BoardStatsService $stats,
        private readonly RankPercentileUpdater $ranks,
        private readonly \App\Service\IpBanChecker $ipBanChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $io->section('Expiring bans');
        $expiredIpBans = $this->ipBans->purgeExpired($now);
        if ($expiredIpBans > 0) {
            // Otherwise an expired ban keeps biting until the cache window lapses.
            $this->ipBanChecker->invalidate();
        }
        $io->writeln(\sprintf('  IP bans expired:     %d', $expiredIpBans));
        $io->writeln(\sprintf('  Soft bans expired:   %d', $this->softBans->purgeExpired($now)));
        $io->writeln(\sprintf('  Forum bans expired:  %d', $this->forumBans->purgeExpired($now)));

        $io->section('Pruning');
        $io->writeln(\sprintf('  Stale guests removed:        %d', $this->online->purgeStaleGuests($now)));
        $io->writeln(\sprintf('  Expired registrations:       %d', $this->pending->purgeExpired($now)));

        $io->section('Statistics');
        $snapshot = $this->online->snapshot(null, $now);
        $this->stats->refreshRecords(
            $snapshot['userCount'],
            array_map(static fn ($u): string => $u->getUsername(), $snapshot['users']),
            $now,
        );
        $this->stats->recordDailySnapshot();
        $io->writeln(\sprintf('  Online now:  %d users, %d guests', $snapshot['userCount'], $snapshot['guestCount']));

        $io->section('Ranks');
        $updated = $this->ranks->recalculate();
        $io->writeln(\sprintf('  Percentile rank thresholds updated: %d', $updated));

        $io->success('Maintenance complete.');

        return Command::SUCCESS;
    }
}
