<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LevelCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The level curve is the board's most visible shared fiction - members compared
 * levels for years - so the port has to reproduce the original arithmetic exactly.
 * These cases were computed from the formulas in lib/function.php.
 */
final class LevelCalculatorTest extends TestCase
{
    private LevelCalculator $levels;

    protected function setUp(): void
    {
        $this->levels = new LevelCalculator();
    }

    /**
     * exp = floor(posts * sqrt(posts * days))
     */
    #[DataProvider('experienceCases')]
    public function testExperienceMatchesTheOriginalFormula(int $posts, float $days, int $expected): void
    {
        self::assertSame($expected, $this->levels->experience($posts, $days));
    }

    public static function experienceCases(): iterable
    {
        yield 'no posts' => [0, 100.0, 0];
        yield 'no days' => [100, 0.0, 0];
        yield 'one post, one day' => [1, 1.0, 1];
        yield 'hundred posts over a year' => [100, 365.0, (int) floor(100 * sqrt(100 * 365))];
        yield 'thousand over two years' => [1000, 730.0, (int) floor(1000 * sqrt(1000 * 730))];
    }

    /**
     * The original suppressed division-by-zero with @ and let INF and the *string*
     * 'NAN' escape into calclvl(), into string comparisons, and eventually into SQL.
     * Every degenerate input has to produce a real integer here.
     */
    public function testDegenerateInputsProduceFiniteValues(): void
    {
        self::assertSame(0, $this->levels->experience(0, 0.0));
        self::assertSame(0, $this->levels->experience(5, 0.0));
        self::assertSame(0, $this->levels->experience(0, 5.0));

        // A negative post count was reachable: moderators could subtract posts.
        $negative = $this->levels->experience(-100, 30.0);
        self::assertLessThan(0, $negative);
        self::assertIsInt($negative);
    }

    public function testLevelIsNeverBelowOneForNonNegativeExperience(): void
    {
        self::assertSame(1, $this->levels->level(0));
        self::assertSame(1, $this->levels->level(1));
        self::assertGreaterThanOrEqual(1, $this->levels->level(500));
    }

    /**
     * experienceForLevel(L) = floor(L ^ 3.5), with level 1 costing nothing.
     */
    public function testExperienceForLevelMatchesTheOriginalFormula(): void
    {
        self::assertSame(0, $this->levels->experienceForLevel(1));
        self::assertSame(0, $this->levels->experienceForLevel(0));
        self::assertSame((int) floor(2 ** 3.5), $this->levels->experienceForLevel(2));
        self::assertSame((int) floor(10 ** 3.5), $this->levels->experienceForLevel(10));
        self::assertSame((int) floor(50 ** 3.5), $this->levels->experienceForLevel(50));
    }

    /**
     * Crossing exactly onto a threshold must award the level, which is what the
     * `if(calclvlexp($lvl+1)==$exp) $lvl++;` correction in the original did.
     */
    public function testExactThresholdAwardsTheLevel(): void
    {
        foreach ([5, 12, 30, 77] as $level) {
            $threshold = $this->levels->experienceForLevel($level);
            self::assertSame(
                $level,
                $this->levels->level($threshold),
                "Experience exactly equal to level {$level}'s threshold should be level {$level}",
            );
        }
    }

    public function testLevelAndThresholdAreInverses(): void
    {
        for ($level = 2; $level <= 60; ++$level) {
            $atThreshold = $this->levels->experienceForLevel($level);

            self::assertSame($level, $this->levels->level($atThreshold));
            // One point short must still be the previous level.
            self::assertSame($level - 1, $this->levels->level($atThreshold - 1));
        }
    }

    public function testProgressWithinALevelIsBounded(): void
    {
        for ($exp = 0; $exp < 200000; $exp += 3571) {
            $progress = $this->levels->levelProgress($exp);

            self::assertGreaterThanOrEqual(0.0, $progress);
            self::assertLessThanOrEqual(1.0, $progress);
        }
    }

    public function testExperienceIntoLevelPlusRemainderEqualsTheSpan(): void
    {
        foreach ([100, 5000, 250000, 9_000_000] as $exp) {
            $level = $this->levels->level($exp);

            self::assertSame(
                $this->levels->levelSpan($level),
                $this->levels->experienceIntoLevel($exp) + $this->levels->experienceToNextLevel($exp),
            );
        }
    }

    public function testExperiencePerSecondIsNullRatherThanInfinite(): void
    {
        // The original returned INF here, formatted it with sprintf('%01.3f'), and
        // printed "inf" into signatures.
        self::assertNull($this->levels->experiencePerSecond(0, 100.0));
        self::assertNull($this->levels->experiencePerSecond(100, 0.0));

        self::assertIsFloat($this->levels->experiencePerSecond(100, 100.0));
    }

    public function testExperiencePerPostGrowsWithAccountAge(): void
    {
        $young = $this->levels->experiencePerPost(500, 30.0);
        $old = $this->levels->experiencePerPost(500, 3000.0);

        self::assertGreaterThan($young, $old);
    }
}
