<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\BoardWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may do what.
 *
 * The original decided this in its templates: editpost.php performed no ownership
 * check whatsoever, so anyone who guessed a post id could edit or delete any post on
 * the board, and forum.php only applied a forum's minimum power level when the
 * visitor was logged in - so a guest could read restricted forums that a member
 * could not. Those are the two holes this file exists to keep shut.
 *
 * Every case is stated as a permission, not as an implementation detail, so the
 * assertions stay meaningful if the voters are refactored.
 */
final class AccessControlTest extends BoardWebTestCase
{
    // ------------------------------------------------------------------
    // Restricted forums
    // ------------------------------------------------------------------

    public function testGuestsCannotReadAStaffOnlyForum(): void
    {
        $this->client->request('GET', '/forum/'.$this->id('forum', 'Staff discussion'));
        $this->assertDenied();
    }

    /** The original's actual bug: the guest branch skipped the power check entirely. */
    public function testGuestsCannotReadThreadsInsideAStaffOnlyForum(): void
    {
        $this->client->request('GET', '/thread/'.$this->id('thread', 'staff'));
        $this->assertDenied();
    }

    public function testOrdinaryMembersCannotReadAStaffOnlyForum(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/forum/'.$this->id('forum', 'Staff discussion'));
        $this->assertDenied();
    }

    public function testStaffCanReadAStaffOnlyForum(): void
    {
        $this->signInAs('LocalMod');
        $this->assertPageLoads('/forum/'.$this->id('forum', 'Staff discussion'));
    }

    /** A restricted forum must not be advertised on the index to those who cannot enter. */
    public function testRestrictedForumsAreHiddenFromTheIndex(): void
    {
        $this->client->request('GET', '/');
        self::assertStringNotContainsString('Staff discussion', $this->client->getResponse()->getContent());

        $this->signInAs('Mod');
        $this->client->request('GET', '/');
        self::assertStringContainsString('Staff discussion', $this->client->getResponse()->getContent());
    }

    /** Nor may its threads leak through search. */
    public function testSearchDoesNotLeakRestrictedThreads(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/search?'.http_build_query(['q' => 'Staff business']));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Staff business', $this->client->getResponse()->getContent());
    }

    /** Nor through the Atom feed, which bypasses the HTML templates entirely. */
    public function testFeedsDoNotLeakRestrictedForums(): void
    {
        $this->client->request('GET', '/forum/'.$this->id('forum', 'Staff discussion').'/feed.atom');
        $this->assertDenied();
    }

    // ------------------------------------------------------------------
    // Post ownership
    // ------------------------------------------------------------------

    public function testAMemberMayEditTheirOwnPost(): void
    {
        $this->signInAs('Member');
        $this->assertPageLoads('/post/'.$this->id('post', 'first').'/edit');
    }

    /** The original's worst bug: no ownership check on editpost.php at all. */
    public function testAMemberMayNotEditSomebodyElsesPost(): void
    {
        $this->signInAs('Other');
        $this->client->request('GET', '/post/'.$this->id('post', 'first').'/edit');
        $this->assertDenied();
    }

    public function testAMemberMayNotDeleteSomebodyElsesPost(): void
    {
        $this->signInAs('Other');
        $this->post('/post/'.$this->id('post', 'first').'/delete', [], 'delete-post'.$this->id('post', 'first'));
        $this->assertDenied();
    }

    public function testAModeratorMayEditAnybodysPost(): void
    {
        $this->signInAs('Mod');
        $this->assertPageLoads('/post/'.$this->id('post', 'first').'/edit');
    }

    /**
     * Deleting the opening post would orphan the thread. The original decided this
     * by the post's index on the current page, so the same post was deletable or not
     * depending on which page you were looking at.
     */
    public function testAMemberMayNotDeleteTheOpeningPostOfTheirOwnThread(): void
    {
        $this->signInAs('Member');
        $this->post('/post/'.$this->id('post', 'first').'/delete', [], 'delete-post'.$this->id('post', 'first'));
        $this->assertDenied();
    }

    public function testNobodyButAModeratorMayEditAPostInAClosedThread(): void
    {
        $threadId = $this->id('thread', 'closed');
        $postId = $this->firstPostIdOf($threadId);

        $this->signInAs('Member');
        $this->client->request('GET', '/post/'.$postId.'/edit');
        $this->assertDenied();

        $this->signInAs('Mod');
        $this->assertPageLoads('/post/'.$postId.'/edit');
    }

    // ------------------------------------------------------------------
    // Staff and administration
    // ------------------------------------------------------------------

    public function testMembersCannotReachTheStaffPanel(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/staff');
        $this->assertDenied();
    }

    public function testMembersCannotReachTheAdminPanel(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/admin');
        $this->assertDenied();
    }

    /** Moderators are staff, but administration is a separate grade. */
    public function testModeratorsCannotReachTheAdminPanel(): void
    {
        $this->signInAs('Mod');
        $this->client->request('GET', '/admin');
        $this->assertDenied();
    }

    public function testMembersCannotEditOtherPeoplesAccounts(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/admin/users/'.$this->id('user', 'Other'));
        $this->assertDenied();
    }

    public function testMembersCannotModerateThreads(): void
    {
        $this->signInAs('Member');
        $threadId = $this->id('thread', 'open');

        foreach (['sticky', 'close', 'trash', 'delete'] as $action) {
            $this->post('/thread/'.$threadId.'/'.$action, [], 'moderate'.$threadId);
            $this->assertDenied(\sprintf('A member managed to %s a thread.', $action));
        }
    }

    /**
     * A local moderator's authority stops at the forums they were given. This is the
     * distinction the original never drew - power level 1 was checked globally.
     */
    public function testALocalModeratorOnlyModeratesTheirOwnForum(): void
    {
        $this->signInAs('LocalMod');

        // General discussion is theirs.
        $this->assertPageLoads('/post/'.$this->id('post', 'first').'/edit');

        // The staff forum is readable to them, but not theirs to moderate.
        $this->client->request('GET', '/post/'.$this->id('post', 'staff').'/edit');
        $this->assertDenied('A local moderator edited a post outside their forum.');
    }

    // ------------------------------------------------------------------
    // Banned accounts
    // ------------------------------------------------------------------

    public function testABannedMemberCanStillReadTheBoard(): void
    {
        // Deliberate: the original let banned users browse and appeal, and the port
        // keeps ROLE_USER for exactly that reason.
        $this->signInAs('Banned');
        $this->assertPageLoads('/thread/'.$this->id('thread', 'open'));
    }

    public function testABannedMemberCannotPost(): void
    {
        $this->signInAs('Banned');
        $this->client->request('GET', '/thread/'.$this->id('thread', 'open').'/reply');
        $this->assertDenied();
    }

    public function testABannedMemberCannotSendMessages(): void
    {
        $this->signInAs('Banned');
        $this->client->request('GET', '/messages/send/'.$this->id('user', 'Member'));
        $this->assertDenied();
    }

    // ------------------------------------------------------------------
    // Per-forum posting thresholds
    // ------------------------------------------------------------------

    public function testAMemberCannotStartThreadsInAnAnnouncementsForum(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/forum/'.$this->id('forum', 'Announcements').'/new-thread');
        $this->assertDenied();
    }

    public function testAnAdministratorCanStartThreadsInAnAnnouncementsForum(): void
    {
        $this->signInAs('Admin');
        $this->assertPageLoads('/forum/'.$this->id('forum', 'Announcements').'/new-thread');
    }

    public function testNobodyMayReplyToAClosedThread(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/thread/'.$this->id('thread', 'closed').'/reply');
        $this->assertDenied();
    }

    // ------------------------------------------------------------------
    // Personal data
    // ------------------------------------------------------------------

    public function testOnlyAdministratorsSeePosterIpAddresses(): void
    {
        $threadUri = '/thread/'.$this->id('thread', 'open');

        $this->signInAs('Member');
        $this->client->request('GET', $threadUri);
        self::assertStringNotContainsString('203.0.113.10', $this->client->getResponse()->getContent());

        $this->signInAs('Mod');
        $this->client->request('GET', $threadUri);
        self::assertStringNotContainsString(
            '203.0.113.10',
            $this->client->getResponse()->getContent(),
            'A moderator is not an administrator.',
        );

        $this->signInAs('Admin');
        $this->client->request('GET', $threadUri);
        self::assertStringContainsString('203.0.113.10', $this->client->getResponse()->getContent());
    }

    public function testAMemberCannotRateThemselves(): void
    {
        $this->signInAs('Member');
        $id = $this->id('user', 'Member');

        $this->post('/profile/'.$id.'/rate', ['rating' => 5], 'rate'.$id);
        $this->assertDenied('Rating yourself skews every average on the board.');
    }

    // ------------------------------------------------------------------
    // CSRF
    // ------------------------------------------------------------------

    /**
     * Every state-changing POST must reject a request without a token. The board is
     * deliberately session-backed rather than using Symfony 7.4's stateless default,
     * which needs JavaScript - so this is the check that it is wired at all.
     */
    public function testStateChangingPostsRequireACsrfToken(): void
    {
        $this->signInAs('Mod');
        $threadId = $this->id('thread', 'open');

        $endpoints = [
            '/thread/'.$threadId.'/sticky',
            '/thread/'.$threadId.'/close',
            '/thread/'.$threadId.'/favorite',
            '/profile/'.$this->id('user', 'Other').'/rate',
            '/mark-read',
        ];

        foreach ($endpoints as $uri) {
            $this->client->request('POST', $uri, ['rating' => 5]);
            self::assertNotSame(
                Response::HTTP_FOUND,
                $this->client->getResponse()->getStatusCode(),
                $uri.' accepted a POST with no CSRF token.',
            );
        }
    }

    public function testAValidCsrfTokenIsAccepted(): void
    {
        $this->signInAs('Mod');
        $threadId = $this->id('thread', 'open');

        // Establish a session first so a token can be minted against it.
        $this->client->request('GET', '/thread/'.$threadId);
        $this->post('/thread/'.$threadId.'/sticky', [], 'moderate'.$threadId);

        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            'A correctly signed request should be accepted; otherwise the test above proves nothing.',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function assertDenied(string $message = ''): void
    {
        $status = $this->client->getResponse()->getStatusCode();

        self::assertContains(
            $status,
            [Response::HTTP_FOUND, Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND],
            $message ?: \sprintf(
                '%s should have been refused, but returned %d.',
                $this->client->getRequest()->getUri(),
                $status,
            ),
        );
    }

    private function firstPostIdOf(int $threadId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM posts WHERE thread_id = ? ORDER BY id LIMIT 1',
            [$threadId],
        );
    }
}
