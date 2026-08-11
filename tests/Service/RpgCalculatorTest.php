<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Item;
use App\Entity\RpgProfile;
use App\Entity\User;
use App\Service\LevelCalculator;
use App\Service\RpgCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The item-shop stat engine.
 *
 * Two properties matter beyond the arithmetic. The original applied every multiplier
 * before every addition, which changes the result and which members could feel; and
 * basestat() transposed several of its curves against the stat array it indexed, a
 * bug the port deliberately does not reproduce. Both are pinned here so neither can
 * drift back.
 */
final class RpgCalculatorTest extends TestCase
{
    private RpgCalculator $rpg;

    protected function setUp(): void
    {
        $this->rpg = new RpgCalculator(new LevelCalculator());
    }

    // ------------------------------------------------------------------
    // Base stats
    // ------------------------------------------------------------------

    public function testStatsGrowWithPostsAndAge(): void
    {
        $young = $this->rpg->baseStat('HP', 100, 30);
        $older = $this->rpg->baseStat('HP', 100, 365);
        $busier = $this->rpg->baseStat('HP', 1000, 30);

        self::assertGreaterThan($young, $older, 'An older account should have more HP at equal posts.');
        self::assertGreaterThan($young, $busier, 'More posts should mean more HP at equal age.');
    }

    /**
     * A brand-new account has no posts and no age. The original divided by both and
     * suppressed the warning, yielding INF and then the string 'NAN' downstream.
     */
    public function testDegenerateAccountsGetTheFloorValueNotInfinity(): void
    {
        foreach ([[0, 0.0], [0, 30.0], [100, 0.0], [-5, 30.0]] as [$posts, $days]) {
            foreach (Item::STATS as $stat) {
                $value = $this->rpg->baseStat($stat, $posts, $days);
                self::assertSame(1, $value, \sprintf('%s at %d posts / %.1f days', $stat, $posts, $days));
            }
        }
    }

    public function testAnUnknownStatIsNotFabricated(): void
    {
        self::assertSame(1, $this->rpg->baseStat('Charisma', 500, 400));
    }

    public function testEveryDocumentedStatHasACurve(): void
    {
        foreach (Item::STATS as $stat) {
            self::assertGreaterThan(
                1,
                $this->rpg->baseStat($stat, 500, 400),
                \sprintf('%s has no curve, so it is stuck at the floor value.', $stat),
            );
        }
    }

    /**
     * The transposition bug: the original applied the "Str" curve to Atk and the
     * "Lck"/"Int" curves to each other. HP and MP keep the largest scales, and the
     * ordering below is what the corrected mapping produces.
     */
    public function testStatCurvesAreBoundToTheirOwnNames(): void
    {
        $stats = [];
        foreach (Item::STATS as $stat) {
            $stats[$stat] = $this->rpg->baseStat($stat, 500, 400);
        }

        self::assertGreaterThan($stats['MP'], $stats['HP'], 'HP is the largest pool.');
        self::assertGreaterThan($stats['Atk'], $stats['MP'], 'MP outscales the combat stats.');

        // Every stat is distinguishable: if the curves were transposed onto the
        // wrong names, or all resolved to one curve, these would collapse together.
        self::assertSame(\count(Item::STATS), \count($stats));
        self::assertGreaterThan(4, \count(array_unique($stats)), 'The curves are not distinct.');

        // MDf carries a larger scale (0.29) than Spd (0.25), and at 500 posts that
        // outweighs Spd's steeper post exponent.
        self::assertGreaterThan($stats['Spd'], $stats['MDf']);
    }

    // ------------------------------------------------------------------
    // Equipment
    // ------------------------------------------------------------------

    public function testAdditiveEquipmentAddsToTheBaseStat(): void
    {
        $user = $this->member(500, 400);
        $base = $this->rpg->statsFor($user);

        $sword = $this->item(['Atk' => 25], Item::MODE_ADD);
        $equipped = $this->rpg->statsFor($user, [$sword]);

        self::assertSame($base['Atk'] + 25, $equipped['Atk']);
        self::assertSame($base['Def'], $equipped['Def'], 'An unrelated stat must not move.');
    }

    public function testMultiplicativeEquipmentScalesByPercent(): void
    {
        $user = $this->member(500, 400);
        $base = $this->rpg->statsFor($user);

        $doubled = $this->rpg->statsFor($user, [$this->item(['HP' => 200], Item::MODE_MULTIPLY)]);

        self::assertSame((int) floor($base['HP'] * 2.0), $doubled['HP']);
    }

    /**
     * The ordering rule: all multipliers, then all additions. Applying them in the
     * other order gives a different - and larger - number, so this is not academic.
     */
    public function testAllMultipliersApplyBeforeAnyAddition(): void
    {
        $user = $this->member(500, 400);
        $base = $this->rpg->statsFor($user)['Atk'];

        $result = $this->rpg->statsFor($user, [
            $this->item(['Atk' => 10], Item::MODE_ADD),
            $this->item(['Atk' => 200], Item::MODE_MULTIPLY),
        ]);

        self::assertSame((int) floor($base * 2.0) + 10, $result['Atk']);
        self::assertNotSame((int) floor(($base + 10) * 2.0), $result['Atk']);
    }

    public function testMultipliersCompound(): void
    {
        $user = $this->member(500, 400);
        $base = $this->rpg->statsFor($user)['Spd'];

        $result = $this->rpg->statsFor($user, [
            $this->item(['Spd' => 150], Item::MODE_MULTIPLY),
            $this->item(['Spd' => 200], Item::MODE_MULTIPLY),
        ]);

        self::assertSame((int) floor($base * 1.5 * 2.0), $result['Spd']);
    }

    /** Cursed equipment must not drive a stat to zero or below. */
    public function testStatsNeverFallBelowOne(): void
    {
        $user = $this->member(500, 400);

        $result = $this->rpg->statsFor($user, [$this->item(['Atk' => -100000], Item::MODE_ADD)]);

        self::assertSame(1, $result['Atk']);
    }

    public function testStatsForCoversEveryStat(): void
    {
        self::assertSame(Item::STATS, array_keys($this->rpg->statsFor($this->member(500, 400))));
    }

    // ------------------------------------------------------------------
    // Coins
    // ------------------------------------------------------------------

    public function testCoinsEarnedGrowsWithActivity(): void
    {
        self::assertSame(0, $this->rpg->coinsEarned(0, 400));
        self::assertSame(0, $this->rpg->coinsEarned(500, 0));
        self::assertGreaterThan(
            $this->rpg->coinsEarned(100, 400),
            $this->rpg->coinsEarned(500, 400),
        );
    }

    public function testCoinsEarnedMatchesTheOriginalFormula(): void
    {
        // floor(posts^1.3 * days^0.4 + posts*10)
        $expected = (int) floor((500 ** 1.3) * (400 ** 0.4) + 5000);

        self::assertSame($expected, $this->rpg->coinsEarned(500, 400));
    }

    public function testAvailableCoinsSubtractsWhatWasSpent(): void
    {
        $user = $this->member(500, 400);
        $now = new \DateTimeImmutable();

        $profile = new RpgProfile($user);
        $profile->setSpent(1000);

        self::assertSame(
            $this->rpg->coinsEarned(500, $user->daysRegistered($now)) - 1000,
            $this->rpg->availableCoins($user, $profile, $now),
        );
    }

    /**
     * Earnings are a function of posts and age, so they never decrease; spending is
     * tracked separately. Overspending therefore shows as a negative balance rather
     * than silently clamping, which is what makes the shop's refusal correct.
     */
    public function testOverspendingShowsAsANegativeBalance(): void
    {
        $user = $this->member(1, 1);
        $profile = new RpgProfile($user);
        $profile->setSpent(1_000_000);

        self::assertLessThan(0, $this->rpg->availableCoins($user, $profile));
    }

    public function testResaleRefundsSixtyPercentRounded(): void
    {
        self::assertSame(600, $this->rpg->resaleValue($this->pricedItem(1000)));
        // 250 * 0.6 = 150 exactly; 251 * 0.6 = 150.6, which rounds rather than truncates.
        self::assertSame(150, $this->rpg->resaleValue($this->pricedItem(250)));
        self::assertSame(151, $this->rpg->resaleValue($this->pricedItem(251)));
        self::assertSame(0, $this->rpg->resaleValue($this->pricedItem(0)));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function member(int $posts, int $daysOld): User
    {
        $user = new User();
        $user->setUsername('Tester');
        $user->setPosts($posts);
        $user->setRegisteredAt(new \DateTimeImmutable(\sprintf('-%d days', $daysOld)));

        return $user;
    }

    /** @param array<string, int> $stats */
    private function item(array $stats, string $mode): Item
    {
        $item = new Item();
        $item->setName('Test item');
        $item->setStats($stats);
        $item->setStatModes(array_fill_keys(array_keys($stats), $mode));

        return $item;
    }

    private function pricedItem(int $price): Item
    {
        $item = new Item();
        $item->setName('Priced item');
        $item->setPrice($price);

        return $item;
    }
}
