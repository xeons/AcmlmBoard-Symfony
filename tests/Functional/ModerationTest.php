<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Forum;
use App\Entity\Thread;
use App\Repository\ActionLogRepository;
use App\Repository\ForumBanRepository;
use App\Repository\SoftBanRepository;
use App\Service\IpBanChecker;
use App\Service\ModerationService;
use App\Service\PostingGuard;
use App\Tests\Support\BoardWebTestCase;

/**
 * Moderation: thread actions, the three kinds of ban, and the audit trail.
 *
 * The original had no audit trail at all, and its bans were checked by whichever
 * page happened to remember to check them. Two things here are worth stating
 * plainly, because both were bugs found during the port: every event listener was
 * silently inert for a while because of a wrong attribute namespace, and the IP-ban
 * cache was never invalidated, so a new ban did not take effect until the cache
 * happened to expire.
 */
final class ModerationTest extends BoardWebTestCase
{
    // ------------------------------------------------------------------
    // Thread actions
    // ------------------------------------------------------------------

    public function testAModeratorCanStickyCloseAndReopenAThread(): void
    {
        $thread = $this->thread('open');
        $mod = $this->user('Mod');

        $this->moderation()->setSticky($thread, true, $mod);
        self::assertTrue($thread->isSticky());

        $this->moderation()->setClosed($thread, true, $mod);
        self::assertTrue($thread->isClosed());

        $this->moderation()->setClosed($thread, false, $mod);
        self::assertFalse($thread->isClosed());
    }

    public function testTrashingMovesTheThreadToTheTrashForum(): void
    {
        $thread = $this->thread('open');

        self::assertTrue($this->moderation()->trash($thread, $this->user('Mod')));
        self::assertTrue($thread->getForum()->isTrash());
    }

    /** Every action a moderator takes is recorded, with who did it. */
    public function testModerationIsWrittenToTheAuditLog(): void
    {
        $before = $this->logSize();

        $this->moderation()->setSticky($this->thread('open'), true, $this->user('Mod'), '203.0.113.50');

        self::assertGreaterThan($before, $this->logSize(), 'Nothing was written to the action log.');
    }

    // ------------------------------------------------------------------
    // Soft bans
    // ------------------------------------------------------------------

    public function testASoftBanStopsSomebodyPostingWithoutBanningTheAccount(): void
    {
        $target = $this->user('Member');

        $this->moderation()->softBan($target, new \DateTimeImmutable('+7 days'), $this->user('Mod'), 'Being tiresome');

        self::assertFalse($target->isBanned(), 'A soft ban is not a ban on the account.');
        self::assertNotNull($this->container()->get(SoftBanRepository::class)->findActiveFor($target));

        $reason = $this->guard()->refusalReasonForReply($target, $this->thread('open'));
        self::assertNotNull($reason, 'A soft-banned member was allowed to post.');
        self::assertStringContainsString('suspended', $reason);
    }

    /** An expired ban must stop applying on its own, without anyone lifting it. */
    public function testAnExpiredSoftBanNoLongerApplies(): void
    {
        $target = $this->user('Member');

        $ban = $this->moderation()->softBan($target, new \DateTimeImmutable('-1 day'), $this->user('Mod'));
        self::assertNotNull($ban);

        self::assertNull($this->container()->get(SoftBanRepository::class)->findActiveFor($target));
        self::assertNull($this->guard()->refusalReasonForReply($target, $this->thread('open')));
    }

    // ------------------------------------------------------------------
    // Forum bans
    // ------------------------------------------------------------------

    public function testAForumBanOnlyAppliesToThatForum(): void
    {
        $target = $this->user('Member');
        $general = $this->forum('General discussion');

        $this->moderation()->forumBan($target, $general, null, $this->user('Mod'), 'Off topic');

        $bans = $this->container()->get(ForumBanRepository::class);
        self::assertTrue($bans->isBanned($target, $general));
        self::assertFalse($bans->isBanned($target, $this->forum('Introductions')), 'The ban leaked to another forum.');

        self::assertStringContainsString(
            'banned from posting in this forum',
            (string) $this->guard()->refusalReasonForReply($target, $this->thread('open')),
        );
    }

    // ------------------------------------------------------------------
    // IP bans
    // ------------------------------------------------------------------

    /**
     * The cache-invalidation bug: bans were cached and the cache was never cleared,
     * so a ban issued now did not bite until the entry expired.
     */
    public function testANewIpBanTakesEffectImmediately(): void
    {
        $checker = $this->container()->get(IpBanChecker::class);

        // Warm the cache with a negative answer first - that is what made the bug
        // survive: the "not banned" result was the one that stuck.
        self::assertFalse($checker->isBanned('198.51.100.44'));

        $this->moderation()->banIp('198.51.100.44', null, $this->user('Admin'), 'Spam');

        self::assertTrue($checker->isBanned('198.51.100.44'), 'The ban did not take effect until the cache expired.');
    }

    public function testAnIpBanCanCoverACidrRange(): void
    {
        $checker = $this->container()->get(IpBanChecker::class);

        $this->moderation()->banIp('198.51.100.0/24', null, $this->user('Admin'), 'Whole range');

        self::assertTrue($checker->isBanned('198.51.100.1'));
        self::assertTrue($checker->isBanned('198.51.100.254'));
        self::assertFalse($checker->isBanned('198.51.101.1'), 'The range matched too much.');
    }

    /**
     * The original matched with INSTR($ip, ban) = 1 - a prefix match on the raw
     * string - so banning 10.0.0.1 also caught 10.0.0.10 through 10.0.0.19, and
     * banning "1" caught a quarter of the internet. Real subnet arithmetic now.
     */
    public function testASingleAddressBanDoesNotCatchItsNeighboursByPrefix(): void
    {
        $checker = $this->container()->get(IpBanChecker::class);

        $this->moderation()->banIp('198.51.100.7', null, $this->user('Admin'), 'One address');

        self::assertTrue($checker->isBanned('198.51.100.7'));
        self::assertFalse($checker->isBanned('198.51.100.70'), 'A string prefix match crept back in.');
        self::assertFalse($checker->isBanned('198.51.100.77'));
    }

    /**
     * Wildcards were the original's syntax and are not this board's. The form has to
     * say so rather than accept a ban that silently matches nothing.
     */
    public function testTheBanFormRejectsAWildcardRatherThanAcceptingADeadBan(): void
    {
        $this->signInAs('Admin');

        $this->client->request('GET', '/admin/ip-bans');
        $this->client->submitForm('Ban', ['ip_ban[ipRange]' => '198.51.100.*']);

        self::assertFalse(
            $this->client->getResponse()->isRedirect(),
            'A wildcard ban was accepted; it would never match anything.',
        );
        self::assertStringContainsString('CIDR', $this->client->getResponse()->getContent());
    }

    public function testAnExpiredIpBanDoesNotApply(): void
    {
        $checker = $this->container()->get(IpBanChecker::class);

        $this->moderation()->banIp('198.51.100.77', new \DateTimeImmutable('-1 hour'), $this->user('Admin'));

        self::assertFalse($checker->isBanned('198.51.100.77'));
    }

    public function testAnUnknownAddressIsNotBanned(): void
    {
        $checker = $this->container()->get(IpBanChecker::class);

        self::assertFalse($checker->isBanned(null));
        self::assertFalse($checker->isBanned(''));
        self::assertFalse($checker->isBanned('203.0.113.200'));
    }

    // ------------------------------------------------------------------
    // Posting rules
    // ------------------------------------------------------------------

    public function testABannedAccountIsRefusedWithTheRightReason(): void
    {
        $reason = $this->guard()->refusalReasonForReply($this->user('Banned'), $this->thread('open'));

        self::assertStringContainsString('banned from this board', (string) $reason);
    }

    public function testAClosedThreadIsRefusedBeforeAnythingElseIsChecked(): void
    {
        // The original assigned each refusal over the last, so the message shown was
        // whichever check ran last rather than the one that applied.
        $reason = $this->guard()->refusalReasonForReply($this->user('Banned'), $this->thread('closed'));

        self::assertStringContainsString('closed', (string) $reason);
    }

    /** An unread warning from staff blocks posting until it has been read. */
    public function testUnreadSystemMessagesBlockPosting(): void
    {
        $target = $this->user('Member');

        $this->container()->get(\App\Service\MessageManager::class)
            ->sendSystem($this->user('Mod'), $target, 'Read this', 'A warning.');

        self::assertStringContainsString(
            'unread system messages',
            (string) $this->guard()->refusalReasonForReply($target, $this->thread('open')),
        );
    }

    public function testAnOrdinaryMemberInAnOrdinaryThreadIsAllowedToPost(): void
    {
        // Member, not Other: Other holds the seeded thread's last reply, and the
        // board refuses consecutive replies from the same person.
        self::assertNull(
            $this->guard()->refusalReasonForReply($this->user('Member'), $this->thread('open')),
            'The seeded board should permit a normal reply; otherwise the refusals above prove nothing.',
        );
    }

    public function testStartingAThreadIsRefusedWhereThePowerLevelIsTooLow(): void
    {
        self::assertNotNull(
            $this->guard()->refusalReasonForNewThread($this->user('Member'), $this->forum('Announcements')),
        );
        self::assertNull(
            $this->guard()->refusalReasonForNewThread($this->user('Admin'), $this->forum('Announcements')),
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function moderation(): ModerationService
    {
        return $this->container()->get(ModerationService::class);
    }

    private function guard(): PostingGuard
    {
        return $this->container()->get(PostingGuard::class);
    }

    private function thread(string $key): Thread
    {
        return $this->em()->find(Thread::class, $this->id('thread', $key));
    }

    private function forum(string $title): Forum
    {
        return $this->em()->find(Forum::class, $this->id('forum', $title));
    }

    private function logSize(): int
    {
        return \count($this->container()->get(ActionLogRepository::class)->findAll());
    }
}
