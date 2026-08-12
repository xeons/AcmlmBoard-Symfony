<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\RankSet;
use App\Repository\RankSetRepository;
use App\Service\RankResolver;
use App\Tests\Support\BoardWebTestCase;

/**
 * The rank ladder shown under a username.
 *
 * The original recomputed the percentile set's thresholds on *every page load*, by
 * walking the whole users table - updategb(). Here the percentiles are data and a
 * scheduled command recalculates the counts, so the read path is a lookup.
 *
 * One deliberate quirk is preserved: outside the percentile set the post count wraps
 * every ten thousand, so the ladder repeats. It looks like a bug and is not.
 */
final class RankResolverTest extends BoardWebTestCase
{
    public function testARankIsTheHighestRungTheCountReaches(): void
    {
        $mario = $this->set('Mario');

        self::assertSame('Non-poster', $this->rankName($mario, 0));
        self::assertSame('Newcomer', $this->rankName($mario, 1));
        self::assertSame('Newcomer', $this->rankName($mario, 9));
        self::assertSame('Micro-Goomba', $this->rankName($mario, 10), 'Exactly on a threshold earns it.');
        self::assertSame('Micro-Goomba', $this->rankName($mario, 19));
        self::assertSame('Goomba', $this->rankName($mario, 20));
        self::assertSame('Red Goomba', $this->rankName($mario, 35));
    }

    public function testTheHighestReachableRungAppliesJustBelowTheWrap(): void
    {
        // Star Mario at 5000 is the top of the ladder proper. The rung above it,
        // "Climbing the ranks again!", sits at exactly 10000 - which the modulo
        // below turns straight back into 0, so it can never be earned. That is a
        // consequence of preserving the original's wrap, not a fault in the
        // resolver; it is asserted so the oddity stays on the record.
        self::assertSame('Star Mario', $this->rankName($this->set('Mario'), 9999));
        self::assertSame('Non-poster', $this->rankName($this->set('Mario'), 10_000));
    }

    /** The wrap: ten thousand and one posts starts the ladder again. */
    public function testTheLadderRepeatsEveryTenThousandPosts(): void
    {
        $mario = $this->set('Mario');

        self::assertSame('Non-poster', $this->rankName($mario, 10_000));
        self::assertSame('Goomba', $this->rankName($mario, 10_020));
        self::assertSame($this->rankName($mario, 137), $this->rankName($mario, 20_137));
    }

    /** The percentile set is exempt from the wrap; it is about standing, not count. */
    public function testThePercentileSetDoesNotWrap(): void
    {
        $global = $this->set('Global ranking');

        // 10500 wraps to 500 in an ordinary set, which would land on the "Double
        // silver axe" rung at 450. Without the wrap it stays above the highest
        // fixed rung, at 1000.
        self::assertSame('Double silver axe', $this->rankName($global, 500));
        self::assertSame('Metal battleaxe', $this->rankName($global, 10_500));
    }

    /**
     * The full ladder from the original: twelve fixed rungs and nine whose
     * threshold updategb() rewrote on every page load.
     */
    public function testThePercentileLadderHasEveryRung(): void
    {
        $rungs = $this->container()->get(\App\Repository\RankRepository::class)
            ->findBy(['rankSet' => $this->set('Global ranking')]);

        self::assertCount(21, $rungs, 'The Global ranking ladder lost rungs.');

        $percentiles = array_values(array_filter(array_map(
            static fn ($rank): ?float => $rank->getPercentile(),
            $rungs,
        )));
        sort($percentiles);

        self::assertSame([0.001, 0.01, 0.03, 0.06, 0.1, 0.2, 0.3, 0.5, 0.7], $percentiles);
    }

    /**
     * Until the scheduled recalculation has run, a percentile rung must be out of
     * reach. Seeding them at zero posts instead - which is what the invented
     * ladder did - hands the top badge to every member on a board too small to
     * have a ranked population at all.
     */
    public function testPercentileRungsAreUnreachableBeforeTheyAreRecalculated(): void
    {
        $global = $this->set('Global ranking');

        // The busiest seeded member is nowhere near the sentinel.
        self::assertSame('Metal battleaxe', $this->rankName($global, 50_000));
    }

    // ------------------------------------------------------------------
    // The sprites
    // ------------------------------------------------------------------

    /**
     * Nearly every rank in the original was a picture over a name. The ladders
     * were seeded as bare text for a while, so the rank column under a post was a
     * word where the board had always shown a Goomba.
     */
    public function testRankLabelsCarryTheirSprite(): void
    {
        $label = $this->resolver()->resolve($this->set('Mario'), 20)?->getLabel();

        self::assertNotNull($label);
        self::assertStringContainsString('<img src="/images/ranks/goomba.gif"', $label);
        self::assertStringContainsString('Goomba', $label);
    }

    public function testTheGlobalLadderUsesTheSlicedSpriteSheet(): void
    {
        // The original served these through images/gb/rankimg.php, which blitted a
        // 25x15 window out of ranks.png per request.
        $label = $this->resolver()->resolve($this->set('Global ranking'), 0)?->getLabel();

        self::assertNotNull($label);
        self::assertStringContainsString('<img src="/images/gb/rank23.png"', $label);
        self::assertStringNotContainsString('rankimg.php', $label);
    }

    /** Whatever a label points at has to actually be on disk. */
    public function testEveryRankSpriteExists(): void
    {
        $projectDir = $this->container()->getParameter('kernel.project_dir');
        $checked = 0;

        foreach ($this->container()->get(\App\Repository\RankRepository::class)->findAll() as $rank) {
            if (!preg_match('~<img src="([^"]+)"~', $rank->getLabel(), $match)) {
                continue;
            }

            ++$checked;
            self::assertFileExists(
                $projectDir.'/public'.$match[1],
                \sprintf('Rank "%s" points at a sprite that is not there.', strip_tags($rank->getLabel())),
            );
        }

        self::assertGreaterThan(150, $checked, 'The ladders lost their sprites.');
    }

    public function testAMemberWithNoRankSetHasNoRank(): void
    {
        $user = $this->user('Member');
        $user->setRankSet(null);

        self::assertSame('', $this->resolver()->resolveLabel($user));
        self::assertNull($this->resolver()->resolve(null, 5000));
    }

    // ------------------------------------------------------------------
    // The rank block under a post
    // ------------------------------------------------------------------

    public function testAnOrdinaryMemberShowsTheirEarnedRankAndNoBadge(): void
    {
        $block = $this->resolver()->resolveBlock($this->user('Member'));

        self::assertNotNull($block['rank']);
        self::assertNull($block['title']);
        self::assertNull($block['badge'], 'Regular members have no staff badge.');
    }

    public function testStaffShowTheirBadge(): void
    {
        $block = $this->resolver()->resolveBlock($this->user('Mod'));

        self::assertSame('Moderator', $block['badge']);
    }

    /** The original hid the staff badge whenever a custom title was set. */
    public function testACustomTitleReplacesTheStaffBadge(): void
    {
        $mod = $this->user('Mod');
        $mod->setTitle('Keeper of the peace');

        $block = $this->resolver()->resolveBlock($mod);

        self::assertSame('Keeper of the peace', $block['title']);
        self::assertNull($block['badge'], 'The badge should give way to a custom title.');
    }

    public function testABlankCustomTitleIsIgnored(): void
    {
        $mod = $this->user('Mod');
        $mod->setTitle('   ');

        $block = $this->resolver()->resolveBlock($mod);

        self::assertNull($block['title']);
        self::assertSame('Moderator', $block['badge'], 'Whitespace should not suppress the badge.');
    }

    public function testTheOwnerIsPresentedAsAnAdministrator(): void
    {
        self::assertSame('Administrator', $this->resolver()->resolveBlock($this->user('Owner'))['badge']);
    }

    public function testABannedMemberIsLabelledAsSuch(): void
    {
        self::assertSame('Banned', $this->resolver()->resolveBlock($this->user('Banned'))['badge']);
    }

    private function resolver(): RankResolver
    {
        return $this->container()->get(RankResolver::class);
    }

    /** The rank's name with its sprite stripped off, which is what reads in a test. */
    private function rankName(RankSet $set, int $posts): ?string
    {
        $label = $this->resolver()->resolve($set, $posts)?->getLabel();

        return null === $label ? null : trim(strip_tags($label));
    }

    private function set(string $name): RankSet
    {
        $set = $this->container()->get(RankSetRepository::class)->findOneBy(['name' => $name]);
        self::assertNotNull($set, \sprintf('The "%s" rank set is missing.', $name));

        return $set;
    }
}
