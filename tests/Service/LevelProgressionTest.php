<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\LevelCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The progression side of the level curve: bands, bars and projections.
 *
 * LevelCalculatorTest pins the curve itself. This covers what the profile page draws
 * on top of it - the EXP bar, "experience to next level", and the "posts needed for
 * level N" panel - none of which the original could compute without producing INF or
 * the string 'NAN' for accounts that were new, empty or post-stripped.
 */
final class LevelProgressionTest extends TestCase
{
    private LevelCalculator $levels;

    protected function setUp(): void
    {
        $this->levels = new LevelCalculator();
    }

    // ------------------------------------------------------------------
    // Bands
    // ------------------------------------------------------------------

    public function testALevelBandStartsWhereTheLastOneEnded(): void
    {
        for ($level = 1; $level <= 25; ++$level) {
            $span = $this->levels->levelSpan($level);

            self::assertSame(
                $this->levels->experienceForLevel($level + 1) - $this->levels->experienceForLevel($level),
                $span,
                'Level '.$level,
            );
            self::assertGreaterThan(0, $span, 'Level '.$level.' has no width, so the bar would divide by zero.');
        }
    }

    public function testTheExperienceForALevelIsExactlyEnoughToReachIt(): void
    {
        // The boundary is the case rounding most easily gets wrong: landing exactly
        // on the threshold must buy the level, not leave you one short of it.
        for ($level = 2; $level <= 40; ++$level) {
            self::assertSame(
                $level,
                $this->levels->level($this->levels->experienceForLevel($level)),
                'Exactly enough experience for level '.$level.' did not award it.',
            );
        }
    }

    public function testOneShortOfTheThresholdIsStillThePreviousLevel(): void
    {
        for ($level = 3; $level <= 40; ++$level) {
            self::assertSame(
                $level - 1,
                $this->levels->level($this->levels->experienceForLevel($level) - 1),
                'One experience short of level '.$level.' should still be level '.($level - 1),
            );
        }
    }

    public function testLevelOneIsFree(): void
    {
        self::assertSame(0, $this->levels->experienceForLevel(1));
        self::assertSame(0, $this->levels->experienceForLevel(0));
        self::assertSame(1, $this->levels->level(0));
    }

    // ------------------------------------------------------------------
    // The experience bar
    // ------------------------------------------------------------------

    public function testProgressIsZeroAtTheStartOfALevelAndApproachesOneAtTheEnd(): void
    {
        $level = 20;
        $start = $this->levels->experienceForLevel($level);
        $end = $this->levels->experienceForLevel($level + 1);

        self::assertSame(0.0, $this->levels->levelProgress($start));
        self::assertGreaterThan(0.9, $this->levels->levelProgress($end - 1));
        self::assertLessThanOrEqual(1.0, $this->levels->levelProgress($end - 1));
    }

    /** Whatever the input, the bar has to be drawable. */
    public function testProgressIsAlwaysBetweenZeroAndOne(): void
    {
        foreach ([0, 1, 42, 1_000, 1_000_000, 999_999_999, -50] as $experience) {
            $progress = $this->levels->levelProgress($experience);

            self::assertGreaterThanOrEqual(0.0, $progress, 'At '.$experience);
            self::assertLessThanOrEqual(1.0, $progress, 'At '.$experience);
        }
    }

    public function testExperienceIntoAndToNextAccountForTheWholeBand(): void
    {
        $experience = 50_000;
        $level = $this->levels->level($experience);

        self::assertSame(
            $this->levels->levelSpan($level),
            $this->levels->experienceIntoLevel($experience) + $this->levels->experienceToNextLevel($experience),
            'The two halves of the bar do not add up to the band.',
        );
    }

    public function testExperienceToNextLevelIsPositiveWhileThereIsALevelToReach(): void
    {
        foreach ([0, 1, 100, 10_000, 500_000] as $experience) {
            self::assertGreaterThan(0, $this->levels->experienceToNextLevel($experience), 'At '.$experience);
        }
    }

    // ------------------------------------------------------------------
    // Rates
    // ------------------------------------------------------------------

    public function testExperiencePerPostGrowsWithAccountSize(): void
    {
        self::assertGreaterThan(
            $this->levels->experiencePerPost(100, 400),
            $this->levels->experiencePerPost(1000, 400),
        );
    }

    public function testRatesAreZeroOrUnknownForAnEmptyAccount(): void
    {
        // The original produced INF here and printed it on the profile page.
        self::assertSame(0, $this->levels->experiencePerPost(0, 400));
        self::assertSame(0, $this->levels->experiencePerPost(100, 0));

        self::assertNull($this->levels->experiencePerSecond(0, 400));
        self::assertNull($this->levels->experiencePerSecond(100, 0));
    }

    public function testTheGainPerSecondIsAFiniteNumberForARealAccount(): void
    {
        $rate = $this->levels->experiencePerSecond(500, 400);

        self::assertNotNull($rate);
        self::assertTrue(is_finite($rate));
        self::assertGreaterThan(0.0, $rate);
    }

    // ------------------------------------------------------------------
    // Projections
    // ------------------------------------------------------------------

    public function testAlreadyHavingALevelNeedsNoFurtherPosts(): void
    {
        $user = $this->member(500, 400);

        $projection = $this->levels->projectLevel($user, 2);

        self::assertSame(0, $projection['postsNeeded']);
        self::assertNull($projection['projectedAt'], 'There is nothing to project towards.');
    }

    public function testReachingAHigherLevelNeedsMorePosts(): void
    {
        $user = $this->member(500, 400);
        $now = new \DateTimeImmutable();

        $near = $this->levels->projectLevel($user, $this->levels->levelFor($user, $now) + 1);
        $far = $this->levels->projectLevel($user, $this->levels->levelFor($user, $now) + 10);

        self::assertGreaterThan(0, $near['postsNeeded']);
        self::assertGreaterThan($near['postsNeeded'], $far['postsNeeded']);
    }

    public function testAProjectedDateIsInTheFuture(): void
    {
        $user = $this->member(500, 400);
        $now = new \DateTimeImmutable();

        $projection = $this->levels->projectLevel($user, $this->levels->levelFor($user, $now) + 5, $now);

        self::assertNotNull($projection['projectedAt']);
        self::assertGreaterThan($now, $projection['projectedAt']);
    }

    /**
     * A member with no posts has no rate, so there is no date at which they arrive.
     * The original divided by zero and offered a date in 1970.
     */
    public function testAnAccountWithNoPostsGetsNoProjectedDate(): void
    {
        $projection = $this->levels->projectLevel($this->member(0, 400), 10);

        self::assertNull($projection['projectedAt']);
        self::assertGreaterThan(0, $projection['postsNeeded']);
    }

    public function testABrandNewAccountDoesNotProduceAnImpossibleProjection(): void
    {
        $projection = $this->levels->projectLevel($this->member(0, 0), 10);

        self::assertNull($projection['projectedAt']);
        self::assertNull($projection['postsNeeded'], 'Without any account age there is nothing to extrapolate from.');
    }

    /** An unreachably distant goal must not offer a date 400 years out. */
    public function testAnAbsurdTargetIsNotGivenADate(): void
    {
        $projection = $this->levels->projectLevel($this->member(5, 400), 9999);

        self::assertNull($projection['projectedAt']);
    }

    // ------------------------------------------------------------------
    // Per-user helpers
    // ------------------------------------------------------------------

    public function testTheUserHelpersAgreeWithTheRawCurve(): void
    {
        $user = $this->member(500, 400);
        $now = new \DateTimeImmutable();

        $experience = $this->levels->experience(500, $user->daysRegistered($now));

        self::assertSame($experience, $this->levels->experienceFor($user, $now));
        self::assertSame($this->levels->level($experience), $this->levels->levelFor($user, $now));
    }

    private function member(int $posts, int $daysOld): User
    {
        $user = new User();
        $user->setUsername('Tester');
        $user->setPosts($posts);
        $user->setRegisteredAt(new \DateTimeImmutable(\sprintf('-%d days', $daysOld)));

        return $user;
    }
}
