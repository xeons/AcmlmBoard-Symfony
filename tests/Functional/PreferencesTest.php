<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\ColorSchemeRepository;
use App\Repository\ThreadLayoutRepository;
use App\Tests\Support\BoardWebTestCase;

/**
 * Member preferences: timezone, colour scheme, post layout and the display options.
 *
 * The timezone field is the one worth the most attention. It used to be an integer
 * offset the member had to work out for themselves and then correct twice a year;
 * it is now a named zone, and the point of the change is that the board follows
 * daylight saving without anyone touching anything.
 */
final class PreferencesTest extends BoardWebTestCase
{
    public function testAMemberCanChooseANamedTimezoneAndTheBoardUsesIt(): void
    {
        $user = $this->signInAs('Member');
        $user->setTimezone('UTC');
        $this->em()->flush();

        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save', ['edit_profile[timezone]' => 'Asia/Tokyo']);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em()->clear();
        self::assertSame('Asia/Tokyo', $this->user('Member')->getTimezone());
    }

    /** The selector must offer real IANA zones, not a list of numeric offsets. */
    public function testTheTimezoneFieldOffersNamedZones(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/edit');

        $options = $this->timezoneOptions($crawler);

        self::assertGreaterThan(100, \count($options), 'The timezone list looks too short to be the IANA database.');
        self::assertContains('America/Chicago', $options);
        self::assertContains('Europe/London', $options);
    }

    /**
     * A member's own stored zone has to be one of the options.
     *
     * New accounts default to UTC, and the intl timezone list spells that "Etc/UTC"
     * - it has no bare "UTC" entry. When the two disagree the select renders with
     * nothing selected, the browser shows whichever option happens to be first, and
     * saving anything else on the page silently moves the member to that zone.
     */
    public function testAMembersOwnZoneIsSelectableWhateverItIsSetTo(): void
    {
        $this->signInAs('Member');

        foreach (['UTC', 'America/Chicago', 'Pacific/Auckland'] as $zone) {
            // Re-read each time: the kernel reboots between requests, so an entity
            // held from before the last one is detached and flushing it is a no-op.
            $this->user('Member')->setTimezone($zone);
            $this->em()->flush();

            $crawler = $this->assertPageLoads('/profile/edit');

            self::assertContains(
                $zone,
                $this->timezoneOptions($crawler),
                \sprintf('"%s" is stored on the account but is not offered in the timezone list.', $zone),
            );
            self::assertSame(
                $zone,
                $crawler->filter('#edit_profile_timezone option[selected]')->attr('value'),
                \sprintf('"%s" is not preselected, so saving the form would change it.', $zone),
            );
        }
    }

    /** Saving the profile without touching the zone must not move the member. */
    public function testSavingTheProfileLeavesAnUntouchedTimezoneAlone(): void
    {
        $user = $this->signInAs('Member');
        $user->setTimezone('UTC');
        $this->em()->flush();

        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save', ['edit_profile[location]' => 'Somewhere']);

        $this->em()->clear();
        self::assertSame('UTC', $this->user('Member')->getTimezone(), 'The timezone moved on its own.');
    }

    /** The favourites table only, without the page chrome or the flash message. */
    private function favouriteListing(): string
    {
        $crawler = $this->assertPageLoads('/favorites');
        $table = $crawler->filter('table.table')->last();

        return 0 === $table->count() ? '' : $table->text();
    }

    /** @return list<string> */
    private function timezoneOptions(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('#edit_profile_timezone option')->each(
            static fn ($node): string => (string) $node->attr('value'),
        );
    }

    public function testAnInvalidTimezoneIsRejected(): void
    {
        $user = $this->signInAs('Member');
        $user->setTimezone('UTC');
        $this->em()->flush();

        $this->client->request('POST', '/profile/edit', [
            'edit_profile' => ['timezone' => 'Mars/Olympus_Mons'],
        ]);

        $this->em()->clear();
        self::assertSame('UTC', $this->user('Member')->getTimezone(), 'A nonsense zone was saved.');
    }

    /** Timestamps on the page follow the member's zone, not the server's. */
    public function testPostTimesAreShownInTheMembersOwnZone(): void
    {
        $user = $this->signInAs('Member');
        $threadUri = '/thread/'.$this->id('thread', 'open');

        $user->setTimezone('Pacific/Kiritimati'); // UTC+14
        $this->em()->flush();
        $east = $this->client->request('GET', $threadUri)->text();

        $user->setTimezone('Pacific/Midway'); // UTC-11
        $this->em()->flush();
        $west = $this->client->request('GET', $threadUri)->text();

        self::assertNotSame($east, $west, 'The page reads identically 25 hours apart, so the zone is being ignored.');
    }

    // ------------------------------------------------------------------
    // Appearance
    // ------------------------------------------------------------------

    public function testAMemberCanChangeColourScheme(): void
    {
        $user = $this->signInAs('Member');
        $schemes = $this->container()->get(ColorSchemeRepository::class)->findAllOrdered();
        $target = end($schemes);

        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save', ['edit_profile[colorScheme]' => (string) $target->getId()]);

        $this->em()->clear();
        self::assertSame($target->getSlug(), $this->user('Member')->getColorScheme()->getSlug());
    }

    public function testAMemberCanChangePostLayout(): void
    {
        $this->signInAs('Member');
        $layouts = $this->container()->get(ThreadLayoutRepository::class)->findAllOrdered();
        $target = end($layouts);

        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save', ['edit_profile[threadLayout]' => (string) $target->getId()]);

        $this->em()->clear();
        self::assertSame($target->getSlug(), $this->user('Member')->getThreadLayout()->getSlug());
    }

    public function testPageSizePreferencesAreApplied(): void
    {
        $user = $this->signInAs('Member');
        $user->setPostsPerPage(1);
        $this->em()->flush();

        // The seeded thread has two posts, so a page size of one must split it.
        $crawler = $this->assertPageLoads('/thread/'.$this->id('thread', 'open'));

        self::assertStringNotContainsString(
            'A reply from somebody else.',
            $crawler->text(),
            'The second post appeared on a one-post page.',
        );
    }

    // ------------------------------------------------------------------
    // Blocking somebody's layout
    // ------------------------------------------------------------------

    /**
     * The original let members put arbitrary HTML and CSS in their post header. The
     * escape hatch is being able to switch an individual's layout off.
     */
    public function testAMemberCanBlockAnotherMembersLayout(): void
    {
        $this->signInAs('Member');
        $otherId = $this->id('user', 'Other');

        $this->client->request('GET', '/profile/'.$otherId);
        $this->post('/profile/'.$otherId.'/block-layout', [], 'block'.$otherId);

        $this->em()->clear();
        self::assertTrue(
            $this->user('Member')->hasBlockedLayoutOf($this->user('Other')),
            'The block was not recorded.',
        );

        $this->assertPageLoads('/blocked-layouts');
    }

    // ------------------------------------------------------------------
    // Favourites
    // ------------------------------------------------------------------

    public function testAThreadCanBeFavouritedAndUnfavourited(): void
    {
        $this->signInAs('Member');
        $threadId = $this->id('thread', 'open');

        $this->client->request('GET', '/thread/'.$threadId);
        $this->post('/thread/'.$threadId.'/favorite', [], 'favorite'.$threadId);

        // Scoped to the listing: the confirmation flash quotes the thread title too,
        // so asserting against the whole page would never see it disappear.
        self::assertStringContainsString('A perfectly ordinary thread', $this->favouriteListing());

        $this->post('/thread/'.$threadId.'/favorite', [], 'favorite'.$threadId);

        self::assertStringNotContainsString('A perfectly ordinary thread', $this->favouriteListing());
    }
}
