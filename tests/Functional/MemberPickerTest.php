<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Forum;
use App\Tests\Support\BoardWebTestCase;

/**
 * Naming a member on the forms that act on one.
 *
 * Both of these used to be a numeric "Member ID" box, which is only discoverable
 * by hovering a profile link and reading the id out of the URL. The original got
 * away with that because its own links were `?u=N`; there is no reason to make
 * anyone do it now.
 *
 * The name is resolved server-side, so the forms work with scripting off - the
 * type-ahead only fills in suggestions. The remove buttons still carry an id,
 * because the server rendered them, and that keeps a member whose name happens
 * to be "12" from standing in for member 12.
 */
final class MemberPickerTest extends BoardWebTestCase
{
    // ------------------------------------------------------------- post radar

    public function testAMemberCanBeAddedToTheRadarByName(): void
    {
        $this->signInAs('Member');

        $this->post('/post-radar', ['action' => 'add', 'member' => 'Other'], 'post-radar');
        $this->assertRedirectsTo('/post-radar');

        $this->client->followRedirect();
        self::assertStringContainsString('Other added to your radar.', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnknownNameIsReported(): void
    {
        $this->signInAs('Member');

        $this->post('/post-radar', ['action' => 'add', 'member' => 'Nobody At All'], 'post-radar');

        $this->client->followRedirect();
        self::assertStringContainsString('No member by that name.', (string) $this->client->getResponse()->getContent());
    }

    /** Names are matched the way the board matches them everywhere else. */
    public function testTheNameMatchIsNotCaseSensitive(): void
    {
        $this->signInAs('Member');

        $this->post('/post-radar', ['action' => 'add', 'member' => 'oTHER'], 'post-radar');

        $this->client->followRedirect();
        self::assertStringContainsString('Other added to your radar.', (string) $this->client->getResponse()->getContent());
    }

    public function testTheRadarStillRemovesById(): void
    {
        $this->signInAs('Member');

        $this->post('/post-radar', ['action' => 'add', 'member' => 'Other'], 'post-radar');
        $this->post('/post-radar', ['action' => 'remove', 'rival' => (string) $this->user('Other')->getId()], 'post-radar');

        $this->client->followRedirect();
        self::assertStringContainsString('Other removed from your radar.', (string) $this->client->getResponse()->getContent());
    }

    public function testTheRadarFormAsksForANameRatherThanANumber(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/post-radar');

        self::assertSame(0, $crawler->filter('input[type="number"]')->count(), 'The radar still asks for a number.');
        self::assertSame(1, $crawler->filter('input[name="member"][data-member-picker]')->count());
    }

    // -------------------------------------------------------- forum moderators

    public function testAModeratorCanBeAppointedByName(): void
    {
        $this->signInAs('Owner');
        $forumId = $this->id('forum', 'General discussion');

        $this->post('/admin/forums/'.$forumId.'/moderators', ['action' => 'add', 'member' => 'Member'], 'moderators'.$forumId);
        $this->assertRedirectsTo('/moderators');

        $this->em()->clear();
        $forum = $this->em()->find(Forum::class, $forumId);
        self::assertNotNull($forum);
        self::assertTrue($forum->isModeratedBy($this->user('Member')), 'The appointment did not take.');
    }

    public function testAppointingAnUnknownNameIsReported(): void
    {
        $this->signInAs('Owner');
        $forumId = $this->id('forum', 'General discussion');

        $this->post('/admin/forums/'.$forumId.'/moderators', ['action' => 'add', 'member' => 'Nobody At All'], 'moderators'.$forumId);

        $this->client->followRedirect();
        self::assertStringContainsString('No member by that name.', (string) $this->client->getResponse()->getContent());
    }

    public function testTheModeratorFormAsksForANameRatherThanANumber(): void
    {
        $this->signInAs('Owner');
        $crawler = $this->assertPageLoads('/admin/forums/'.$this->id('forum', 'General discussion').'/moderators');

        self::assertSame(0, $crawler->filter('input[type="number"]')->count());
        self::assertSame(1, $crawler->filter('input[name="member"][data-member-picker]')->count());
    }

    // ------------------------------------------------------------ the lookup

    public function testTheLookupReturnsMatchingNames(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/members/find?q=Mem');

        $this->assertStatus(200);
        $names = array_column(
            json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR),
            'name',
        );

        self::assertContains('Member', $names);
    }

    /** A single letter would match most of the board, so it answers with nothing. */
    public function testTheLookupIgnoresAVeryShortQuery(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/members/find?q=M');

        $this->assertStatus(200);
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testTheLookupIsClosedToGuests(): void
    {
        $this->client->request('GET', '/members/find?q=Mem');

        self::assertTrue(
            $this->client->getResponse()->isRedirect() || 401 === $this->client->getResponse()->getStatusCode(),
            'The member lookup should not answer guests.',
        );
    }
}
