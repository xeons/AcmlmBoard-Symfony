<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * The board's experience and level curve.
 *
 * These formulas are load-bearing folklore - members compared levels, the post radar
 * raced on them, and the RPG stats derive from them - so they are reproduced exactly
 * as lib/function.php defined them:
 *
 *     exp   = floor(posts * sqrt(posts * days))          when posts/days > 0
 *     level = floor(exp ^ (2/7))
 *     exp needed for level L = floor(L ^ 3.5)            (level 1 costs 0)
 *
 * What has changed is the failure behaviour. The original suppressed every warning
 * with @, so a user with zero days registered produced a division by zero that
 * evaluated to INF, and a negative post count (possible, because moderators could
 * subtract posts) produced the *string* 'NAN', which then flowed into calclvl(),
 * string comparisons, and finally into SQL. Those cases return explicit, typed
 * values here.
 */
final class LevelCalculator
{
    /** Above this the pow() results stop being meaningful; clamps runaway input. */
    private const MAX_LEVEL = 10000;

    /**
     * Total experience for a member.
     *
     * A negative post count yields negative experience rather than the original's
     * 'NAN' sentinel, which keeps the return type honest and the ordering sane.
     */
    public function experience(int $posts, float $days): int
    {
        if (0 === $posts || $days <= 0.0) {
            return 0;
        }

        if ($posts < 0) {
            // Mirror the curve into the negatives so a stripped account still sorts
            // below everyone rather than becoming a special case at every call site.
            return -(int) floor(abs($posts) * sqrt(abs($posts) * $days));
        }

        return (int) floor($posts * sqrt($posts * $days));
    }

    public function experienceFor(User $user, ?\DateTimeImmutable $now = null): int
    {
        return $this->experience($user->getPosts(), $user->daysRegistered($now));
    }

    /** Level implied by an experience total. Level 1 is the floor for any non-negative value. */
    public function level(int $experience): int
    {
        if ($experience < 0) {
            return -(int) floor($this->pow(abs($experience), 2 / 7));
        }

        $level = (int) floor($this->pow($experience, 2 / 7));

        // Rounding can leave an exact level boundary one short of the level it buys.
        if ($this->experienceForLevel($level + 1) === $experience) {
            ++$level;
        }

        return max(1, min(self::MAX_LEVEL, $level));
    }

    public function levelFor(User $user, ?\DateTimeImmutable $now = null): int
    {
        return $this->level($this->experienceFor($user, $now));
    }

    /** Cumulative experience required to reach a level. */
    public function experienceForLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        return (int) floor($this->pow(abs($level), 3.5)) * ($level > 0 ? 1 : -1);
    }

    /** Experience contained within one level band. */
    public function levelSpan(int $level): int
    {
        return $this->experienceForLevel($level + 1) - $this->experienceForLevel($level);
    }

    /** Experience still needed to advance. */
    public function experienceToNextLevel(int $experience): int
    {
        return $this->experienceForLevel($this->level($experience) + 1) - $experience;
    }

    /** Experience earned so far inside the current level band. */
    public function experienceIntoLevel(int $experience): int
    {
        return $experience - $this->experienceForLevel($this->level($experience));
    }

    /** Progress through the current level, 0.0 to 1.0, for the EXP bar. */
    public function levelProgress(int $experience): float
    {
        $span = $this->levelSpan($this->level($experience));
        if ($span <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->experienceIntoLevel($experience) / $span));
    }

    /** Experience one more post would be worth right now. */
    public function experiencePerPost(int $posts, float $days): int
    {
        if ($posts <= 0 || $days <= 0.0) {
            return 0;
        }

        return (int) floor(1.5 * sqrt($posts * $days));
    }

    /**
     * Seconds of waiting worth as much experience as one post, shown as the
     * "gain/s" figure. Undefined without posts, where the original produced INF.
     */
    public function experiencePerSecond(int $posts, float $days): ?float
    {
        if ($posts <= 0 || $days <= 0.0) {
            return null;
        }

        return round(172800 * (sqrt($days / $posts) / $posts), 3);
    }

    /**
     * Posts needed to hit a target level at the current account age, and the date it
     * would be reached at the current posting rate. Backs the "for level N" panel.
     *
     * @return array{postsNeeded: int|null, projectedAt: \DateTimeImmutable|null}
     */
    public function projectLevel(User $user, int $targetLevel, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $days = $user->daysRegistered($now);
        $goal = $this->experienceForLevel($targetLevel);
        $current = $this->experienceFor($user, $now);

        if ($current >= $goal) {
            return ['postsNeeded' => 0, 'projectedAt' => null];
        }

        $postsNeeded = null;
        if ($days > 0.0) {
            // Solve goal = p * sqrt(p * days) for p, holding days fixed.
            $postsNeeded = max(0, (int) ceil(($goal / sqrt($days)) ** (2 / 3)) - $user->getPosts());
        }

        $projectedAt = null;
        if ($current > 0 && $days > 0.0) {
            // Solve for the day count at which the current rate reaches the goal.
            $daysNeeded = sqrt($goal / $current) * $days;
            if (is_finite($daysNeeded) && $daysNeeded < 36500) {
                $projectedAt = $user->getRegisteredAt()->modify(\sprintf('+%d days', (int) ceil($daysNeeded)));
            }
        }

        return ['postsNeeded' => $postsNeeded, 'projectedAt' => $projectedAt];
    }

    /** pow() that never returns NAN or INF, so callers never have to test for it. */
    private function pow(float $base, float $exponent): float
    {
        if ($base <= 0.0) {
            return 0.0;
        }

        $result = $base ** $exponent;

        return is_finite($result) ? $result : 0.0;
    }
}
