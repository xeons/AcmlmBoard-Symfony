<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Rank;
use App\Entity\RankSet;
use App\Entity\User;
use App\Repository\RankRepository;

/**
 * Resolves the rank label shown under a username.
 *
 * getrank() in the original ran a `SELECT text FROM ranks WHERE num<=$posts AND
 * rset=$n ORDER BY num DESC LIMIT 1` for every post on the page, and - if the user
 * had picked rank set 3 - also called updategb(), which walked the entire users
 * table and issued up to nine UPDATEs. Per post. Ranks are loaded once and matched
 * in memory here; the percentile recalculation is a scheduled command.
 *
 * One quirk is preserved deliberately: outside the percentile set, the post count is
 * taken modulo 10000, so the ladder repeats every ten thousand posts. That was
 * intentional on a board where the top members had five-digit counts.
 */
final class RankResolver
{
    /** @var array<int, list<Rank>>|null rank set id => rungs, ascending */
    private ?array $cache = null;

    public function __construct(private readonly RankRepository $ranks)
    {
    }

    /**
     * The rank rung a post count earns within a set, or null if the set has no
     * matching rung.
     */
    public function resolve(?RankSet $set, int $posts): ?Rank
    {
        if (null === $set || null === $set->getId()) {
            return null;
        }

        $rungs = $this->rungsFor($set->getId());
        if ([] === $rungs) {
            return null;
        }

        $effective = $set->isPercentileBased() ? $posts : $posts % 10000;

        $match = null;
        foreach ($rungs as $rung) {
            if ($rung->getMinPosts() <= $effective) {
                $match = $rung;
            } else {
                break;
            }
        }

        return $match;
    }

    /** Just the label text; empty string when the user has no rank. */
    public function resolveLabel(User $user, ?int $posts = null): string
    {
        return $this->resolve($user->getRankSet(), $posts ?? $user->getPosts())?->getLabel() ?? '';
    }

    /**
     * The complete rank block for a post: the earned rank, then the custom title if
     * the user has one, then the staff badge. Matches the original's stacking order.
     *
     * @return array{rank: string|null, title: string|null, badge: string|null}
     */
    public function resolveBlock(User $user, ?int $posts = null): array
    {
        $rank = $this->resolve($user->getRankSet(), $posts ?? $user->getPosts())?->getLabel();
        $title = '' !== trim((string) $user->getTitle()) ? $user->getTitle() : null;

        // The original suppressed the staff badge whenever a custom title was set.
        $badge = null === $title ? $user->getPowerLevel()->postRankLabel() : null;

        return ['rank' => $rank, 'title' => $title, 'badge' => $badge];
    }

    /**
     * @return list<Rank> ascending by threshold
     */
    private function rungsFor(int $setId): array
    {
        if (null === $this->cache) {
            $this->cache = [];
            foreach ($this->ranks->findBy([], ['minPosts' => 'ASC']) as $rank) {
                $set = $rank->getRankSet();
                if (null !== $set && null !== $set->getId()) {
                    $this->cache[$set->getId()][] = $rank;
                }
            }
        }

        return $this->cache[$setId] ?? [];
    }
}
