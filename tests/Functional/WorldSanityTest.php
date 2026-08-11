<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\BoardWebTestCase;
use App\Tests\Support\TestWorld;

/**
 * Checks the test world itself before anything relies on it.
 *
 * If the reset between tests ever stops working, the failures it causes surface in
 * whichever test happens to run next and look like application bugs. These two tests
 * fail first and say what actually broke.
 */
final class WorldSanityTest extends BoardWebTestCase
{
    public function testTheSeededBoardIsPresent(): void
    {
        self::assertSame(500, $this->user('Member')->getPosts());
        self::assertTrue($this->user('Banned')->isBanned());
        self::assertTrue($this->user('Admin')->isAdmin());
        self::assertFalse($this->user('Member')->isStaff());

        $this->assertPageLoads('/');
    }

    /**
     * Deliberately mutates shared state; the next test asserts it was undone.
     * PHPUnit runs methods in declaration order, so this pair is meaningful.
     */
    public function testMutationsAreVisibleWithinATest(): void
    {
        $member = $this->user('Member');
        $member->setPosts(999999);
        $this->em()->flush();

        self::assertSame(999999, $this->user('Member')->getPosts());
    }

    public function testTheWorldIsRestoredBetweenTests(): void
    {
        self::assertSame(
            500,
            $this->user('Member')->getPosts(),
            'The previous test set this to 999999; the reset should have undone it.',
        );
    }

    public function testSeededIdsAreStableAcrossResets(): void
    {
        // The ids were captured when the world was built. If TRUNCATE were replaced
        // with DELETE, auto-increment would keep climbing and these would drift.
        self::assertSame(
            $this->id('user', 'Member'),
            (int) $this->user('Member')->getId(),
        );

        $this->assertPageLoads('/thread/'.$this->id('thread', 'open'));
    }

    public function testEveryCastMemberCanSignIn(): void
    {
        foreach (['Owner', 'Admin', 'Mod', 'LocalMod', 'Member', 'Other', 'Banned'] as $name) {
            $this->client->request('GET', '/login');
            $this->client->submitForm('Log in', [
                'username' => $name,
                'password' => TestWorld::PASSWORD,
            ]);

            self::assertTrue(
                $this->client->getResponse()->isRedirect(),
                \sprintf('"%s" should be able to sign in with the seeded password.', $name),
            );

            $this->client->request('POST', '/logout');
        }
    }
}
