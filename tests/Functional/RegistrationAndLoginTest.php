<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PendingRegistration;
use App\Repository\PendingRegistrationRepository;
use App\Repository\UserRepository;
use App\Service\RegistrationService;
use App\Tests\Support\BoardWebTestCase;
use App\Tests\Support\TestWorld;

/**
 * Registering, verifying and signing in.
 *
 * The original stored passwords as bare md5 with no salt, compared usernames by
 * loading the entire users table, and told you plainly which half of a login was
 * wrong. The properties asserted here are the ones that follow from fixing that:
 * hashes are salted and verifiable, names are unique case-insensitively, and no form
 * on the board will confirm whether a given account exists.
 */
final class RegistrationAndLoginTest extends BoardWebTestCase
{
    // ------------------------------------------------------------------
    // Signing in
    // ------------------------------------------------------------------

    public function testAMemberCanSignInAndOutAgain(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', [
            'username' => 'Member',
            'password' => TestWorld::PASSWORD,
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->client->followRedirect();
        self::assertStringContainsString('Member', $this->client->getResponse()->getContent());

        $this->client->request('POST', '/logout');
        $this->client->request('GET', '/');
        self::assertStringContainsString('Login', $this->client->getResponse()->getContent());
    }

    public function testTheWrongPasswordIsRefused(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', [
            'username' => 'Member',
            'password' => 'not the password',
        ]);

        $this->client->followRedirect();
        self::assertStringNotContainsString('You are logged in as', $this->client->getResponse()->getContent());
    }

    /**
     * A wrong password and an unknown account must produce the same message, or the
     * login form becomes a way to enumerate members.
     */
    public function testAnUnknownAccountAndAWrongPasswordAreIndistinguishable(): void
    {
        $unknown = $this->failedLoginMessage('NoSuchMember', 'whatever');
        $wrong = $this->failedLoginMessage('Member', 'not the password');

        self::assertSame($wrong, $unknown, 'The login form reveals whether an account exists.');
    }

    public function testUsernamesAreCaseInsensitiveWhenSigningIn(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', [
            'username' => 'mEmBeR',
            'password' => TestWorld::PASSWORD,
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
    }

    // ------------------------------------------------------------------
    // Password storage
    // ------------------------------------------------------------------

    public function testPasswordsAreNotStoredInAnythingResemblingPlaintextOrBareMd5(): void
    {
        $stored = $this->user('Member')->getPassword();

        self::assertNotSame(TestWorld::PASSWORD, $stored);
        self::assertNotSame(md5(TestWorld::PASSWORD), $stored);
        self::assertDoesNotMatchRegularExpression('/^[a-f0-9]{32}$/', $stored, 'That is a bare md5.');
        self::assertStringStartsWith('$', $stored, 'A modern hash carries its algorithm and cost.');
    }

    /** Two members choosing the same password must not end up with the same hash. */
    public function testIdenticalPasswordsGetDifferentHashes(): void
    {
        $registration = $this->container()->get(RegistrationService::class);

        $one = $registration->createUser('SaltProbeOne', 'one@example.test', 'the-same-password');
        $two = $registration->createUser('SaltProbeTwo', 'two@example.test', 'the-same-password');

        self::assertNotSame($one->getPassword(), $two->getPassword(), 'The hashes are unsalted.');
    }

    // ------------------------------------------------------------------
    // Registering
    // ------------------------------------------------------------------

    public function testRegisteringIssuesAVerificationCodeRatherThanAnAccount(): void
    {
        $error = $this->registration()->requestVerification('Newcomer', 'newcomer@example.test', '203.0.113.60');

        self::assertNull($error);
        self::assertFalse($this->container()->get(UserRepository::class)->usernameExists('Newcomer'));
        self::assertNotNull($this->pending()->findByUsername('Newcomer'), 'No pending registration was recorded.');
    }

    public function testATakenNameIsRefused(): void
    {
        self::assertNotNull($this->registration()->requestVerification('Member', 'someone@example.test', null));
    }

    public function testATakenNameIsRefusedRegardlessOfCasing(): void
    {
        self::assertNotNull($this->registration()->requestVerification('mEmBeR', 'someone@example.test', null));
    }

    /**
     * An address already in use gets the same neutral answer as a fresh one, so the
     * form cannot be used to test whether somebody is registered.
     */
    public function testAnAddressAlreadyInUseIsNotRevealed(): void
    {
        $message = $this->registration()->requestVerification('Fresh', 'member@example.test', null);

        self::assertNotNull($message);
        self::assertStringContainsString('If that address can be used', $message);
        self::assertFalse($this->container()->get(UserRepository::class)->usernameExists('Fresh'));
    }

    public function testTheCorrectCodeCreatesTheAccount(): void
    {
        $code = $this->issueCodeFor('Verifiable', 'verifiable@example.test');

        [$user, $error] = $this->registration()->verify('Verifiable', $code, 'a-good-password-1');

        self::assertNull($error);
        self::assertNotNull($user);
        self::assertSame('Verifiable', $user->getUsername());
        self::assertNull($this->pending()->findByUsername('Verifiable'), 'The pending row should be consumed.');
    }

    public function testAWrongCodeIsRefused(): void
    {
        $this->issueCodeFor('Verifiable', 'verifiable@example.test');

        [$user, $error] = $this->registration()->verify('Verifiable', str_repeat('0', 32), 'a-good-password-1');

        self::assertNull($user);
        self::assertNotNull($error);
    }

    /** A wrong code and an unknown name must give the same answer. */
    public function testAnUnknownNameAndAWrongCodeAreIndistinguishable(): void
    {
        $this->issueCodeFor('Verifiable', 'verifiable@example.test');

        [, $wrongCode] = $this->registration()->verify('Verifiable', str_repeat('0', 32), 'a-good-password-1');
        [, $unknownName] = $this->registration()->verify('NeverRegistered', str_repeat('0', 32), 'a-good-password-1');

        self::assertSame($wrongCode, $unknownName, 'The verification form reveals whether a registration exists.');
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $code = $this->issueCodeFor('Stale', 'stale@example.test');

        $pending = $this->pending()->findByUsername('Stale');
        // expires_at is what isExpired() consults; created_at is only a record.
        $this->em()->getConnection()->executeStatement(
            'UPDATE pending_registrations SET expires_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $pending->getId()],
        );
        $this->em()->clear();

        [$user, $error] = $this->registration()->verify('Stale', $code, 'a-good-password-1');

        self::assertNull($user);
        self::assertStringContainsString('expired', (string) $error);
    }

    /** The code is stored hashed, so a database leak does not hand out accounts. */
    public function testTheVerificationCodeIsNotStoredInTheClear(): void
    {
        $code = $this->issueCodeFor('Hashed', 'hashed@example.test');

        $stored = $this->em()->getConnection()->fetchAssociative(
            'SELECT * FROM pending_registrations WHERE username = ?',
            ['Hashed'],
        );

        foreach ($stored as $column => $value) {
            self::assertNotSame($code, (string) $value, \sprintf('The raw code is stored in "%s".', $column));
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function registration(): RegistrationService
    {
        return $this->container()->get(RegistrationService::class);
    }

    private function pending(): PendingRegistrationRepository
    {
        return $this->container()->get(PendingRegistrationRepository::class);
    }

    /**
     * Registers a name and returns the code that was issued. The code is only ever
     * emailed, so it is regenerated here by writing a known one over the stored hash.
     */
    private function issueCodeFor(string $username, string $email): string
    {
        self::assertNull($this->registration()->requestVerification($username, $email, null));

        $code = bin2hex(random_bytes(16));
        $pending = $this->pending()->findByUsername($username);

        // Rebuild the row with a code this test knows, using the entity's own
        // hashing so the storage format is never assumed.
        $replacement = new PendingRegistration($username, $email, $code, null);
        $this->em()->remove($pending);
        $this->em()->flush();
        $this->em()->persist($replacement);
        $this->em()->flush();

        return $code;
    }

    private function failedLoginMessage(string $username, string $password): string
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['username' => $username, 'password' => $password]);

        return $this->client->followRedirect()->filter('.error, .alert, .flash-error, [role=alert]')->text('');
    }
}
