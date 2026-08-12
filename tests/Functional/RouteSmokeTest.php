<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\BoardWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page the board can render, rendered.
 *
 * This is the test that would have caught the post-preview crash: four templates
 * built a permalink from an unsaved post's id and blew up, and nothing exercised
 * them. A smoke test is shallow by design - it asserts a page renders at all - but
 * "renders at all" is precisely the property a 7,000-line port most easily breaks,
 * and no amount of unit testing substitutes for actually rendering the Twig.
 *
 * Routes are listed explicitly rather than pulled from the router, so that adding a
 * route without deciding who may see it leaves a visible gap.
 */
final class RouteSmokeTest extends BoardWebTestCase
{
    /**
     * Pages a logged-out visitor is entitled to.
     *
     * @return iterable<string, array{string}>
     */
    public static function guestPages(): iterable
    {
        yield 'index' => ['/'];
        yield 'forum' => ['/forum/{forum:General discussion}'];
        yield 'thread' => ['/thread/{thread:open}'];
        yield 'memberlist' => ['/members'];
        yield 'active users' => ['/active-users'];
        yield 'online users' => ['/online'];
        yield 'ranks' => ['/ranks'];
        yield 'calendar' => ['/calendar'];
        yield 'stats' => ['/stats'];
        yield 'profile' => ['/profile/{user:Member}'];
        yield 'threads by user' => ['/user/{user:Member}/threads'];
        yield 'posts by user' => ['/user/{user:Member}/posts'];
        yield 'announcements' => ['/announcements'];
        yield 'faq' => ['/faq'];
        yield 'credits' => ['/credits'];
        yield 'login' => ['/login'];
        yield 'register' => ['/register'];
        yield 'thread feed' => ['/thread/{thread:open}/feed.atom'];
        yield 'forum feed' => ['/forum/{forum:General discussion}/feed.atom'];
        // Public on purpose: the avatar gallery is just pictures, and search is
        // open to guests whenever the board's minimum power for it is 0, which is
        // the default and what the original did.
        yield 'avatars' => ['/avatars'];
        yield 'search' => ['/search'];
        yield 'acs' => ['/acs'];
    }

    /**
     * Pages that require an ordinary account.
     *
     * @return iterable<string, array{string}>
     */
    public static function memberPages(): iterable
    {
        yield 'messages' => ['/messages'];
        yield 'system messages' => ['/messages/system'];
        yield 'read a message' => ['/messages/{message:unread}'];
        yield 'compose' => ['/messages/send/{user:Other}'];
        yield 'message folders' => ['/messages/folders'];
        yield 'favorites' => ['/favorites'];
        yield 'edit profile' => ['/profile/edit'];
        yield 'edit layout' => ['/layout'];
        yield 'blocked layouts' => ['/blocked-layouts'];
        yield 'passkeys' => ['/profile/passkeys'];
        yield 'post radar' => ['/post-radar'];
        yield 'member lookup' => ['/members/find'];
        yield 'reply form' => ['/thread/{thread:open}/reply'];
        yield 'new thread form' => ['/forum/{forum:General discussion}/new-thread'];
        yield 'edit own post' => ['/post/{post:first}/edit'];
        yield 'shop' => ['/shop'];
        yield 'shop category' => ['/shop/{itemCategory:1}'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function staffPages(): iterable
    {
        yield 'staff panel' => ['/staff'];
        yield 'staff user view' => ['/staff/user/{user:Member}'];
        yield 'soft ban form' => ['/staff/user/{user:Member}/soft-ban'];
        yield 'forum ban form' => ['/staff/user/{user:Member}/forum-ban'];
        yield 'system message form' => ['/staff/user/{user:Member}/system-message'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminPages(): iterable
    {
        yield 'admin index' => ['/admin'];
        yield 'board config' => ['/admin/config'];
        yield 'forum admin' => ['/admin/forums'];
        yield 'new forum' => ['/admin/forums/new'];
        yield 'edit forum' => ['/admin/forums/{forum:General discussion}'];
        yield 'forum moderators' => ['/admin/forums/{forum:General discussion}/moderators'];
        yield 'new category' => ['/admin/categories/new'];
        yield 'edit category' => ['/admin/categories/1'];
        yield 'edit user' => ['/admin/users/{user:Member}'];
        yield 'ip bans' => ['/admin/ip-bans'];
        yield 'ip search' => ['/admin/ip-search?ip=203.0.113.10'];
        yield 'action log' => ['/admin/log'];
        // Ratings are staff-facing: the page shows who rated whom.
        yield 'profile ratings' => ['/profile/{user:Member}/ratings'];
        yield 'new announcement' => ['/announcements/new'];
        yield 'edit thread' => ['/thread/{thread:open}/edit'];
        yield 'edit poll' => ['/thread/{thread:open}/poll/edit'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestPages')]
    public function testGuestsCanReach(string $uri): void
    {
        $this->assertPageLoads($this->resolve($uri));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('memberPages')]
    public function testMembersCanReach(string $uri): void
    {
        $this->signInAs('Member');
        $this->assertPageLoads($this->resolve($uri));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffPages')]
    public function testModeratorsCanReach(string $uri): void
    {
        $this->signInAs('Mod');
        $this->assertPageLoads($this->resolve($uri));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminPages')]
    public function testAdministratorsCanReach(string $uri): void
    {
        $this->signInAs('Admin');
        $this->assertPageLoads($this->resolve($uri));
    }

    /**
     * Guests are sent to the login page rather than shown a 500 or, worse, the page.
     *
     * @return iterable<string, array{string}>
     */
    public static function guestsAreTurnedAwayFrom(): iterable
    {
        yield from self::memberPages();
        yield from self::staffPages();
        yield from self::adminPages();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestsAreTurnedAwayFrom')]
    public function testGuestsAreDeniedOrRedirected(string $uri): void
    {
        $this->client->request('GET', $this->resolve($uri));
        $status = $this->client->getResponse()->getStatusCode();

        self::assertContains(
            $status,
            [Response::HTTP_FOUND, Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN],
            \sprintf('%s must not be reachable by a guest, but returned %d.', $uri, $status),
        );
    }

    /**
     * The board renders in every scheme and every post layout.
     *
     * Nineteen schemes and eight layouts is a lot of templates to leave unexecuted,
     * and layouts in particular are the code that broke when previewing a post.
     */
    public function testEverySchemeAndLayoutRenders(): void
    {
        $user = $this->signInAs('Member');
        $em = $this->em();

        $schemes = $this->container()->get(\App\Repository\ColorSchemeRepository::class)->findAllOrdered();
        self::assertGreaterThanOrEqual(19, \count($schemes), 'All the original schemes should be seeded.');

        foreach ($schemes as $scheme) {
            $user->setColorScheme($scheme);
            $em->flush();

            $this->client->request('GET', '/thread/'.$this->id('thread', 'open'));
            self::assertSame(
                Response::HTTP_OK,
                $this->client->getResponse()->getStatusCode(),
                \sprintf('The "%s" scheme should render.', $scheme->getSlug()),
            );
        }

        $layouts = $this->container()->get(\App\Repository\ThreadLayoutRepository::class)->findAllOrdered();
        self::assertCount(9, $layouts, 'All nine post layouts should be seeded.');

        foreach ($layouts as $layout) {
            $user->setThreadLayout($layout);
            $em->flush();

            $this->client->request('GET', '/thread/'.$this->id('thread', 'open'));
            self::assertSame(
                Response::HTTP_OK,
                $this->client->getResponse()->getStatusCode(),
                \sprintf('The "%s" post layout should render.', $layout->getSlug()),
            );
        }
    }

    /**
     * Two routes answer with a redirect rather than a page, by design.
     *
     * A permalink has to work out which page of the thread a post falls on, which is
     * the calculation the original got wrong often enough that "post not on this
     * page" was a familiar sight.
     */
    public function testPermalinksAndForumJumpRedirectToTheRightPlace(): void
    {
        $this->client->request('GET', '/post/'.$this->id('post', 'first'));
        $this->assertRedirectsTo('/thread/'.$this->id('thread', 'open'));

        $this->client->request('GET', '/forum-jump?id='.$this->id('forum', 'Introductions'));
        $this->assertRedirectsTo('/forum/'.$this->id('forum', 'Introductions'));
    }

    /** Missing records give a 404, not a 500 from a null dereference. */
    public function testUnknownIdsAreNotFound(): void
    {
        $this->signInAs('Admin');

        foreach (['/thread/999999', '/forum/999999', '/post/999999', '/profile/999999', '/messages/999999'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $this->client->getResponse()->getStatusCode(),
                $uri.' should be a 404.',
            );
        }
    }

    /**
     * Substitutes {user:Name}, {forum:Title}, {thread:key}, {post:key} and
     * {message:key} with the seeded ids.
     */
    private function resolve(string $uri): string
    {
        return preg_replace_callback(
            '/\{(user|forum|thread|post|message|itemCategory):([^}]+)\}/',
            function (array $m): string {
                if ('itemCategory' === $m[1]) {
                    return $m[2];
                }

                return (string) $this->id($m[1], $m[2]);
            },
            $uri,
        );
    }
}
