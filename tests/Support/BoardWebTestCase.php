<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for tests that drive the board over HTTP.
 *
 * Each test gets the same seeded board (see TestWorld) and a browser that is not
 * logged in. Log in with signInAs('Admin'); the cast is Owner, Admin, Mod, LocalMod,
 * Member, Other and Banned.
 */
abstract class BoardWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Follow nothing by default: a redirect is usually the assertion.
        $this->client->followRedirects(false);
        // Let the tests see the real status code rather than Symfony's error page.
        $this->client->catchExceptions(true);

        TestWorld::reset(static::$kernel);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // WebTestCase shuts the kernel down; make sure nothing holds a stale manager.
        self::ensureKernelShutdown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function signInAs(string $name): User
    {
        $user = $this->user($name);
        $this->client->loginUser($user);

        return $user;
    }

    protected function user(string $name): User
    {
        $user = $this->container()->get(UserRepository::class)->findOneByUsername($name);
        self::assertNotNull($user, \sprintf('The seeded member "%s" is missing.', $name));

        return $user;
    }

    protected function container(): \Psr\Container\ContainerInterface
    {
        return static::getContainer();
    }

    protected function em(): EntityManagerInterface
    {
        return $this->container()->get(EntityManagerInterface::class);
    }

    protected function id(string $type, string $name): int
    {
        return TestWorld::id($type, $name);
    }

    /**
     * Submits a POST with a valid CSRF token for the given intention.
     *
     * Tokens are session-backed, so this asks the running container for one rather
     * than scraping it out of the page - which keeps tests readable and still fails
     * loudly if CSRF protection is removed, because assertCsrf would then reject the
     * token this generates.
     */
    protected function post(string $uri, array $parameters = [], ?string $csrfIntention = null): void
    {
        if (null !== $csrfIntention) {
            $parameters['_token'] = $this->csrfToken($csrfIntention);
        }

        $this->client->request('POST', $uri, $parameters);
    }

    /**
     * A token the board will accept for the given intention.
     *
     * Tokens are session-backed, so one can only be minted inside a request that has
     * a session. The client's session is borrowed and pushed onto the request stack
     * for the duration, which means the *real* token manager issues the token - if
     * the board's CSRF configuration changed, this would produce a token the board
     * rejects, and the test would fail rather than quietly pass.
     */
    protected function csrfToken(string $intention): string
    {
        try {
            $session = $this->client->getRequest()->getSession();
        } catch (\Symfony\Component\BrowserKit\Exception\BadMethodCallException) {
            // No request has been made yet, so there is no session to hang a token
            // on. Visit a page first, exactly as a browser would have to.
            $this->client->request('GET', '/');
            $session = $this->client->getRequest()->getSession();
        }

        $stack = $this->container()->get('request_stack');
        $carrier = new \Symfony\Component\HttpFoundation\Request();
        $carrier->setSession($session);
        $stack->push($carrier);

        try {
            return $this->container()
                ->get('security.csrf.token_manager')
                ->getToken($intention)
                ->getValue();
        } finally {
            $stack->pop();
            // Persist it, or the next request's kernel reads a session without it.
            $session->save();
        }
    }

    protected function assertStatus(int $expected): void
    {
        self::assertSame(
            $expected,
            $this->client->getResponse()->getStatusCode(),
            \sprintf(
                'Expected HTTP %d for %s, got %d.',
                $expected,
                $this->client->getRequest()->getUri(),
                $this->client->getResponse()->getStatusCode(),
            ),
        );
    }

    /** A page rendered without the framework swallowing an exception into a 500. */
    protected function assertPageLoads(string $uri): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            \sprintf('%s should render, got %d.', $uri, $this->client->getResponse()->getStatusCode()),
        );

        return $crawler;
    }

    protected function assertRedirectsTo(string $path): void
    {
        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            \sprintf('Expected a redirect, got %d.', $this->client->getResponse()->getStatusCode()),
        );
        self::assertStringContainsString($path, (string) $this->client->getResponse()->headers->get('Location'));
    }
}
