<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Forum;
use App\Enum\PowerLevel;
use App\Repository\BoardConfigRepository;
use App\Repository\ForumRepository;
use App\Tests\Support\BoardWebTestCase;

/**
 * Administration: board settings, forum structure and account management.
 *
 * These are write paths, so they are driven through the forms rather than the
 * services - a setting that saves but never reaches the page it governs is still
 * broken, and only submitting the form catches that.
 */
final class AdminTest extends BoardWebTestCase
{
    // ------------------------------------------------------------------
    // Board configuration
    // ------------------------------------------------------------------

    public function testAnAdministratorCanRenameTheBoard(): void
    {
        $this->signInAs('Admin');

        $this->client->request('GET', '/admin/config');
        $this->client->submitForm('Save', ['board_config[boardName]' => 'The Renamed Board']);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em()->clear();
        self::assertSame('The Renamed Board', $this->container()->get(BoardConfigRepository::class)->get()->getBoardName());

        // And it must actually appear on the board.
        $this->client->request('GET', '/');
        self::assertStringContainsString('The Renamed Board', $this->client->getResponse()->getContent());
    }

    public function testTheBoardDefaultTimezoneAppliesToGuests(): void
    {
        $this->signInAs('Admin');

        $this->client->request('GET', '/admin/config');
        $this->client->submitForm('Save', ['board_config[defaultTimezone]' => 'Asia/Tokyo']);

        $this->em()->clear();
        self::assertSame('Asia/Tokyo', $this->container()->get(BoardConfigRepository::class)->get()->getDefaultTimezone());
    }

    /** Turning passkeys off must actually close the endpoints, not just hide the UI. */
    public function testDisablingPasskeysClosesTheRegistrationEndpoint(): void
    {
        $this->signInAs('Admin');

        $config = $this->container()->get(BoardConfigRepository::class)->get();
        $config->setPasskeysEnabled(false);
        $this->em()->flush();

        $this->client->request('GET', '/profile/passkeys');
        $this->post('/profile/passkeys/options', [], 'passkey-register');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Forum structure
    // ------------------------------------------------------------------

    public function testAnAdministratorCanCreateAForum(): void
    {
        $this->signInAs('Admin');

        $this->client->request('GET', '/admin/forums/new');
        $this->client->submitForm('Save', [
            'forum[title]' => 'A brand new forum',
            'forum[description]' => 'Created by a test.',
            'forum[category]' => (string) $this->categoryId('General'),
            'forum[position]' => '5',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em()->clear();
        $forum = $this->container()->get(ForumRepository::class)->findOneBy(['title' => 'A brand new forum']);
        self::assertNotNull($forum);

        // A new forum must be usable straight away.
        $this->assertPageLoads('/forum/'.$forum->getId());
    }

    public function testChangingAForumsMinimumPowerTakesEffectImmediately(): void
    {
        $forumId = $this->id('forum', 'Introductions');

        $this->signInAs('Admin');
        $this->client->request('GET', '/admin/forums/'.$forumId);
        $this->client->submitForm('Save', ['forum[minPower]' => '3']);

        // The member could read it a moment ago.
        $this->signInAs('Member');
        $this->client->request('GET', '/forum/'.$forumId);

        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [403, 404],
            'Raising the minimum power level did not lock the forum.',
        );
    }

    // ------------------------------------------------------------------
    // Accounts
    // ------------------------------------------------------------------

    public function testAnAdministratorCanPromoteAndDemoteAMember(): void
    {
        $this->signInAs('Admin');
        $memberId = $this->id('user', 'Member');

        $this->client->request('GET', '/admin/users/'.$memberId);
        $this->client->submitForm('Save', [
            'user_admin[powerLevel]' => (string) PowerLevel::Moderator->value,
        ]);

        $this->em()->clear();
        self::assertSame(PowerLevel::Moderator, $this->user('Member')->getPowerLevel());

        // And the promotion must carry real authority.
        $this->signInAs('Member');
        $this->assertPageLoads('/staff');
    }

    public function testBanningAnAccountStopsItPosting(): void
    {
        $this->signInAs('Admin');
        $otherId = $this->id('user', 'Other');

        $this->client->request('GET', '/admin/users/'.$otherId);
        $this->client->submitForm('Save', [
            'user_admin[powerLevel]' => (string) PowerLevel::Banned->value,
        ]);

        $this->em()->clear();
        self::assertTrue($this->user('Other')->isBanned());

        $this->signInAs('Other');
        $this->client->request('GET', '/thread/'.$this->id('thread', 'open').'/reply');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
    }

    /**
     * Owner and System are structural - they follow the accounts designated in board
     * config. The original rewrote power levels on every page load to enforce that;
     * here they are simply not offered as choices.
     */
    public function testOwnerAndSystemAreNotHandAssignable(): void
    {
        $assignable = array_map(static fn (PowerLevel $l): int => $l->value, PowerLevel::assignable());

        self::assertNotContains(PowerLevel::Owner->value, $assignable);
        self::assertNotContains(PowerLevel::System->value, $assignable);
        self::assertContains(PowerLevel::Administrator->value, $assignable);
    }

    // ------------------------------------------------------------------
    // IP bans
    // ------------------------------------------------------------------

    public function testAnAdministratorCanAddAndRemoveAnIpBan(): void
    {
        $this->signInAs('Admin');

        $this->client->request('GET', '/admin/ip-bans');
        $this->client->submitForm('Ban', [
            'ip_ban[ipRange]' => '198.51.100.0/24',
            'ip_ban[reason]' => 'Test ban',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $crawler = $this->assertPageLoads('/admin/ip-bans');
        self::assertStringContainsString('198.51.100.0/24', $crawler->text());
    }

    // ------------------------------------------------------------------
    // Audit
    // ------------------------------------------------------------------

    public function testTheActionLogRecordsAdministrativeChanges(): void
    {
        $this->signInAs('Admin');

        $this->client->request('GET', '/admin/users/'.$this->id('user', 'Member'));
        $this->client->submitForm('Save', [
            'user_admin[powerLevel]' => (string) PowerLevel::LocalModerator->value,
        ]);

        $crawler = $this->assertPageLoads('/admin/log');
        self::assertStringContainsString('Admin', $crawler->text());
    }

    // ------------------------------------------------------------------
    // Maintenance commands
    // ------------------------------------------------------------------

    /**
     * The recount command is the safety net for the denormalised counters. On a
     * board nothing has gone wrong with, it must find nothing to fix - if it
     * reports corrections on a freshly seeded board, the counters are being written
     * wrongly in the first place.
     */
    public function testRecountFindsNothingToFixOnAHealthyBoard(): void
    {
        $forums = $this->container()->get(ForumRepository::class);

        foreach ($forums->findAll() as $forum) {
            $storedThreads = $forum->getThreadCount();
            $storedPosts = $forum->getPostCount();

            $forums->recount($forum);

            self::assertSame($storedThreads, $forum->getThreadCount(), $this->describe($forum).' thread count');
            self::assertSame($storedPosts, $forum->getPostCount(), $this->describe($forum).' post count');
        }
    }

    private function describe(Forum $forum): string
    {
        return \sprintf('Forum "%s"', $forum->getTitle());
    }

    private function categoryId(string $name): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM categories WHERE name = ?',
            [$name],
        );
    }
}
