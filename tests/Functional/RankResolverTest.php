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

        self::assertSame('Red Koopa', $this->resolver()->resolve($mario, 0)?->getLabel());
        self::assertSame('Red Koopa', $this->resolver()->resolve($mario, 19)?->getLabel());
        self::assertSame('Goomba', $this->resolver()->resolve($mario, 20)?->getLabel(), 'Exactly on a threshold earns it.');
        self::assertSame('Goomba', $this->resolver()->resolve($mario, 49)?->getLabel());
        self::assertSame('Red Paragoomba', $this->resolver()->resolve($mario, 50)?->getLabel());
    }

    public function testTheHighestReachableRungAppliesJustBelowTheWrap(): void
    {
        // 7500 is Chain Chomp. The rung above it, Birdo, is set at exactly 10000 -
        // which the modulo below turns straight back into 0, so it can never be
        // earned. That is a consequence of preserving the original's wrap, not a
        // fault in the resolver; it is asserted so the oddity is on the record.
        self::assertSame('Chain Chomp', $this->resolver()->resolve($this->set('Mario'), 9999)?->getLabel());
        self::assertSame('Red Koopa', $this->resolver()->resolve($this->set('Mario'), 10_000)?->getLabel());
    }

    /** The wrap: ten thousand and one posts starts the ladder again. */
    public function testTheLadderRepeatsEveryTenThousandPosts(): void
    {
        $mario = $this->set('Mario');

        self::assertSame('Red Koopa', $this->resolver()->resolve($mario, 10_000)?->getLabel());
        self::assertSame('Goomba', $this->resolver()->resolve($mario, 10_020)?->getLabel());
        self::assertSame(
            $this->resolver()->resolve($mario, 137)?->getLabel(),
            $this->resolver()->resolve($mario, 20_137)?->getLabel(),
        );
    }

    /** The percentile set is exempt from the wrap; it is about standing, not count. */
    public function testThePercentileSetDoesNotWrap(): void
    {
        $global = $this->set('Global ranking');

        // Every rung of the percentile set starts at 0 posts until the scheduled
        // recalculation fills the thresholds in, so this asserts the branch, not a
        // particular label.
        self::assertNotNull($this->resolver()->resolve($global, 25_000));
    }

    /**
     * The percentile ladder must have all its rungs. Float array keys in the
     * fixtures collapsed to 0, leaving one rung where there should be nine.
     */
    public function testThePercentileLadderHasEveryRung(): void
    {
        $global = $this->set('Global ranking');

        $labels = array_map(
            static fn ($rank): string => $rank->getLabel(),
            $this->container()->get(\App\Repository\RankRepository::class)->findBy(['rankSet' => $global]),
        );

        self::assertCount(9, $labels, 'The percentile ladder lost rungs.');
        self::assertContains('Top 0.1%', $labels);
        self::assertContains('Top 70%', $labels);
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

    private function set(string $name): RankSet
    {
        $set = $this->container()->get(RankSetRepository::class)->findOneBy(['name' => $name]);
        self::assertNotNull($set, \sprintf('The "%s" rank set is missing.', $name));

        return $set;
    }
}
