<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RankSetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recomputes the thresholds of percentile-based rank sets.
 *
 * This replaces updategb() from lib/function.php, which:
 *   - ran on *every page load*, from lib/layout.php and again from getrank()
 *   - selected every user with >= 1000 posts, ordered by post count, and walked the
 *     result set in PHP
 *   - issued up to nine UPDATEs, matching the rank to update by
 *     `text LIKE '%=3%'` - so a rank whose *label* happened to contain "=3" was
 *     silently rewritten
 *
 * The percentile each rung represents is now a column on the rank, and this runs
 * from the maintenance command.
 */
final class RankPercentileUpdater
{
    /** Members below this post count are excluded from the ranked population. */
    private const MINIMUM_POSTS = 1000;

    public function __construct(
        private readonly RankSetRepository $rankSets,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return int number of rungs whose threshold changed
     */
    public function recalculate(): int
    {
        $sets = $this->rankSets->findPercentileBased();
        if ([] === $sets) {
            return 0;
        }

        /** @var list<int> $postCounts descending */
        $postCounts = array_map('intval', $this->users->findPostCountsAbove(self::MINIMUM_POSTS));
        $population = \count($postCounts);

        if (0 === $population) {
            return 0;
        }

        $updated = 0;

        foreach ($sets as $set) {
            foreach ($set->getRanks() as $rank) {
                $percentile = $rank->getPercentile();
                if (null === $percentile) {
                    continue;
                }

                // The post count at the given percentile from the top. floor() so a
                // percentile of 0.01 on a 100-member board picks the single top
                // member rather than nobody.
                $index = min($population - 1, max(0, (int) floor($population * $percentile)));
                $threshold = $postCounts[$index];

                if ($rank->getMinPosts() !== $threshold) {
                    $rank->setMinPosts($threshold);
                    ++$updated;
                }
            }
        }

        if ($updated > 0) {
            $this->em->flush();
        }

        return $updated;
    }
}
