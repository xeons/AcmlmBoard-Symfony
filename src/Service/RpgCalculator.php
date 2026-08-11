<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Entity\RpgProfile;
use App\Entity\User;

/**
 * The item-shop stat engine, ported from lib/rpg.php.
 *
 * Base stats are a function of post count, account age and level. Equipment then
 * applies multiplicatively or additively per stat, and the original applied *all*
 * multipliers before *all* additions - worth preserving, since it changes results.
 *
 * One genuine bug is not preserved: basestat() labelled its cases HP, MP, Str, Atk,
 * Def, Shl, Lck, Int, Spd, but the stat array it was indexed by was HP, MP, Atk,
 * Def, Int, MDf, Dex, Lck, Spd. So the curve intended for "Str" was applied to Atk,
 * "Shl" to MDf, and Lck/Int were transposed. The curves are bound to the real stat
 * names here; the numbers are otherwise identical.
 */
final class RpgCalculator
{
    /**
     * Exponent triples (posts, days, level) and the scale/offset for each stat,
     * exactly as in the original's basestat().
     *
     * @var array<string, array{float, float, float, float, int}>
     */
    private const CURVES = [
        'HP' => [0.26, 0.08, 1.11, 0.95, 20],
        'MP' => [0.22, 0.12, 1.11, 0.32, 10],
        'Atk' => [0.18, 0.04, 1.09, 0.29, 2],
        'Def' => [0.16, 0.07, 1.09, 0.28, 2],
        'Int' => [0.15, 0.09, 1.09, 0.29, 2],
        'MDf' => [0.14, 0.10, 1.09, 0.29, 1],
        'Dex' => [0.17, 0.05, 1.09, 0.29, 2],
        'Lck' => [0.19, 0.03, 1.09, 0.29, 1],
        'Spd' => [0.21, 0.02, 1.09, 0.25, 1],
    ];

    public function __construct(private readonly LevelCalculator $levels)
    {
    }

    /** Unequipped value of one stat. */
    public function baseStat(string $stat, int $posts, float $days): int
    {
        if (!isset(self::CURVES[$stat]) || $posts <= 0 || $days <= 0.0) {
            return 1;
        }

        [$pExp, $dExp, $lExp, $scale, $offset] = self::CURVES[$stat];
        $level = $this->levels->level($this->levels->experience($posts, $days));

        if ($level <= 0) {
            return 1;
        }

        $value = ($posts ** $pExp) * ($days ** $dExp) * ($level ** $lExp) * $scale + $offset;

        return is_finite($value) ? max(1, (int) $value) : 1;
    }

    /**
     * Final stats with equipment applied.
     *
     * @param array<int, Item> $equipped item id => item, as loaded from the loadout
     *
     * @return array<string, int>
     */
    public function statsFor(User $user, array $equipped = [], ?\DateTimeImmutable $now = null): array
    {
        $posts = $user->getPosts();
        $days = $user->daysRegistered($now);

        $multipliers = array_fill_keys(Item::STATS, 1.0);
        $additions = array_fill_keys(Item::STATS, 0);

        foreach ($equipped as $item) {
            foreach (Item::STATS as $stat) {
                if ($item->isMultiplicative($stat)) {
                    $multipliers[$stat] *= $item->getStat($stat) / 100;
                } else {
                    $additions[$stat] += $item->getStat($stat);
                }
            }
        }

        $stats = [];
        foreach (Item::STATS as $stat) {
            $base = $this->baseStat($stat, $posts, $days);
            $stats[$stat] = max(1, (int) floor($base * $multipliers[$stat]) + $additions[$stat]);
        }

        return $stats;
    }

    /**
     * Coins earned to date: floor(posts^1.3 * days^0.4 + posts*10).
     * Spending is tracked separately, so this never decreases.
     */
    public function coinsEarned(int $posts, float $days): int
    {
        if ($posts <= 0 || $days <= 0.0) {
            return 0;
        }

        $coins = ($posts ** 1.3) * ($days ** 0.4) + $posts * 10;

        return is_finite($coins) ? (int) floor($coins) : 0;
    }

    /** Coins the user can actually spend right now. */
    public function availableCoins(User $user, RpgProfile $profile, ?\DateTimeImmutable $now = null): int
    {
        return $this->coinsEarned($user->getPosts(), $user->daysRegistered($now)) - $profile->getSpent();
    }

    /**
     * What selling an item refunds. The original wrote `spent = spent - price*0.6`,
     * i.e. a 60% refund, and did so with a float that MySQL then truncated into an
     * int column. Rounded explicitly here.
     */
    public function resaleValue(Item $item): int
    {
        return (int) round($item->getPrice() * 0.6);
    }
}
