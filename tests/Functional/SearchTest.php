<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\ForumRepository;
use App\Repository\PostRepository;
use App\Service\PostManager;
use App\Tests\Support\BoardWebTestCase;

/**
 * Post search, by text and by IP.
 *
 * Both were completely broken and nothing noticed: the DQL read
 * `LIKE :text ESCAPE :esc`, and DQL will not accept a parameter after ESCAPE - it
 * has to be a literal. Every search with an actual term raised a syntax error, so
 * the only searches that ever worked were empty ones. Hence the escaping tests
 * below: they are what the ESCAPE clause is for, and they are what proves it parses.
 */
final class SearchTest extends BoardWebTestCase
{
    private const NEEDLE = 'quokka';

    // ------------------------------------------------------------------
    // Text search
    // ------------------------------------------------------------------

    public function testSearchingForATermFindsTheMatchingPost(): void
    {
        $this->postSaying('A post about a '.self::NEEDLE.' in the wild.');

        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/search?'.http_build_query(['search' => ['text' => self::NEEDLE]]));

        self::assertStringContainsString(self::NEEDLE, $crawler->text());
    }

    public function testSearchingForATermThatIsNotThereFindsNothing(): void
    {
        $this->signInAs('Member');
        $this->assertPageLoads('/search?'.http_build_query(['search' => ['text' => 'zzzznotpresent']]));

        self::assertSame([], $this->find(text: 'zzzznotpresent'));
    }

    public function testAnEmptySearchStillRenders(): void
    {
        $this->signInAs('Member');
        $this->assertPageLoads('/search');
    }

    // ------------------------------------------------------------------
    // LIKE metacharacters
    //
    // These run against the repository directly: a wildcard leaking through is a
    // matter of which rows come back, which the HTML makes tedious to assert.
    // ------------------------------------------------------------------

    public function testAPercentSignIsSearchedLiterallyAndDoesNotActAsAWildcard(): void
    {
        $this->postSaying('Only 100% of the time.');
        $this->postSaying('Nothing to do with numbers.');

        // A lone "%" is the sharpest test: treated as a wildcard it matches every
        // readable post on the board; treated literally it matches only the one
        // post that actually contains the character.
        $everything = $this->find(text: '');
        self::assertGreaterThan(1, \count($everything), 'The board needs several posts for this to mean anything.');

        $literal = $this->find(text: '%');
        self::assertCount(1, $literal, 'A lone % matched more than the post containing one, so it is still a wildcard.');
        self::assertStringContainsString('100%', $literal[0]->getBody());

        self::assertCount(1, $this->find(text: '100%'));
    }

    public function testAnUnderscoreIsSearchedLiterallyAndDoesNotMatchAnySingleCharacter(): void
    {
        $this->postSaying('snake_case is fine.');
        $this->postSaying('snakeXcase is not the same thing.');

        $found = $this->find(text: 'snake_case');

        self::assertCount(1, $found, 'The underscore matched an arbitrary character.');
        self::assertStringContainsString('snake_case', $found[0]->getBody());
    }

    /**
     * The escape character itself. '=' was chosen precisely because it is
     * unremarkable to DQL and to the database - but that only holds if it is
     * escaped when a member types one.
     */
    public function testTheEscapeCharacterItselfIsSearchedLiterally(): void
    {
        $this->postSaying('The answer is x = 42.');
        $this->postSaying('No equations here.');

        $found = $this->find(text: 'x = 42');

        self::assertCount(1, $found);
        self::assertStringContainsString('x = 42', $found[0]->getBody());
    }

    public function testACombinationOfMetacharactersSurvives(): void
    {
        $this->postSaying('Weird: 50%_off =now=');

        self::assertCount(1, $this->find(text: '50%_off =now='));
    }

    // ------------------------------------------------------------------
    // IP search
    // ------------------------------------------------------------------

    public function testAdministratorsCanSearchByIpAddress(): void
    {
        $this->signInAs('Admin');
        $crawler = $this->assertPageLoads('/admin/ip-search?ip=203.0.113.10');

        // The seeded threads were posted from this address.
        self::assertStringContainsString('203.0.113.10', $crawler->text());
    }

    public function testIpSearchMatchesOnAPrefixSoASubnetCanBeSwept(): void
    {
        self::assertNotEmpty($this->find(ip: '203.0.113.'), 'A partial address should match a whole range.');
        self::assertSame([], $this->find(ip: '198.51.100.'));
    }

    public function testIpSearchWithNoAddressDoesNotFail(): void
    {
        $this->signInAs('Admin');
        $this->assertPageLoads('/admin/ip-search');
    }

    // ------------------------------------------------------------------
    // Visibility
    // ------------------------------------------------------------------

    /**
     * Search runs against a forum list, not against everything. A member must not be
     * able to read staff-only material by searching for it.
     */
    public function testSearchIsScopedToForumsTheViewerMayRead(): void
    {
        $memberScope = $this->container()->get(ForumRepository::class)->findReadableIds(0);
        $staffScope = $this->container()->get(ForumRepository::class)->findReadableIds(1);

        self::assertSame([], $this->find(text: 'Not for the public', forumIds: $memberScope));
        self::assertNotEmpty($this->find(text: 'Not for the public', forumIds: $staffScope));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return list<\App\Entity\Post> */
    private function find(?string $text = null, ?string $ip = null, ?array $forumIds = null): array
    {
        $forumIds ??= $this->container()->get(ForumRepository::class)->findReadableIds(0);

        return array_values(iterator_to_array($this->container()->get(PostRepository::class)->search(
            visibleForumIds: $forumIds,
            text: $text,
            ip: $ip,
            page: 1,
            perPage: 50,
        )));
    }

    private function postSaying(string $body): void
    {
        $this->container()->get(PostManager::class)->reply(
            $this->em()->find(\App\Entity\Thread::class, $this->id('thread', 'open')),
            $this->user('Other'),
            $body,
            '198.51.100.7',
        );
    }
}
