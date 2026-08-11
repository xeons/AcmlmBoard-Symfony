<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\BoardConfigRepository;
use App\Service\TotpService;
use App\Tests\Support\BoardWebTestCase;
use App\Tests\Support\TestWorld;
use OTPHP\TOTP;

/**
 * Two-step sign-in with an authenticator app.
 *
 * The codes are generated here the same way a phone would, from the seed the board
 * issues, so these exercise the real arithmetic rather than a stub.
 */
final class TwoFactorTest extends BoardWebTestCase
{
    // ------------------------------------------------------------------
    // Setting up
    // ------------------------------------------------------------------

    public function testAMemberStartsWithNoSecondFactor(): void
    {
        $user = $this->user('Member');

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpSecret());
        self::assertSame(0, $user->countUnusedRecoveryCodes());
    }

    public function testTheSeedIsStoredEncrypted(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);

        $stored = (string) $this->em()->getConnection()->fetchOne(
            'SELECT totp_secret FROM users WHERE id = ?',
            [$user->getId()],
        );

        self::assertNotSame('', $stored);
        self::assertStringNotContainsString($secret, $stored, 'The seed is in the database in plain text.');
        self::assertSame($secret, $this->totp()->secretFor($user), 'It must still be recoverable.');
    }

    /** Long seeds are valid but nobody can type them into an app by hand. */
    public function testTheSeedIsAReasonableLength(): void
    {
        $secret = $this->totp()->beginSetup($this->user('Member'));

        self::assertSame(32, \strlen($secret), 'Expected a 160-bit base32 seed.');
        self::assertMatchesRegularExpression('~^[A-Z2-7]+$~', $secret);
    }

    /** Abandoning setup must not require a code that has never been proved to work. */
    public function testStartingSetupDoesNotYetRequireAnything(): void
    {
        $user = $this->user('Member');
        $this->totp()->beginSetup($user);

        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testAWrongCodeDoesNotSwitchItOn(): void
    {
        $user = $this->user('Member');
        $this->totp()->beginSetup($user);

        self::assertNull($this->totp()->confirm($user, '000000'));
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testACorrectCodeSwitchesItOnAndIssuesRecoveryCodes(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);

        $codes = $this->totp()->confirm($user, $this->codeFor($secret));

        self::assertIsArray($codes);
        self::assertCount(10, $codes);
        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertNotNull($user->getTotpConfirmedAt());
    }

    /** Recovery codes bypass the second factor, so they are stored like passwords. */
    public function testRecoveryCodesAreStoredHashed(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);
        $codes = $this->totp()->confirm($user, $this->codeFor($secret));

        $stored = (string) $this->em()->getConnection()->fetchOne(
            'SELECT totp_recovery_codes FROM users WHERE id = ?',
            [$user->getId()],
        );

        foreach ($codes as $code) {
            self::assertStringNotContainsString($code, $stored, 'A recovery code is stored in plain text.');
        }
        self::assertTrue($user->isBackupCode($codes[0]));
    }

    public function testRecoveryCodesAreCaseAndPunctuationInsensitive(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);
        $codes = $this->totp()->confirm($user, $this->codeFor($secret));

        $code = $codes[0];

        self::assertTrue($user->isBackupCode(strtolower($code)));
        self::assertTrue($user->isBackupCode(str_replace('-', '', $code)));
        self::assertTrue($user->isBackupCode(str_replace('-', ' ', $code)));
    }

    public function testARecoveryCodeWorksOnlyOnce(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);
        $codes = $this->totp()->confirm($user, $this->codeFor($secret));

        self::assertTrue($user->isBackupCode($codes[0]));
        $user->invalidateBackupCode($codes[0]);

        self::assertFalse($user->isBackupCode($codes[0]), 'A used recovery code still works.');
        self::assertTrue($user->isBackupCode($codes[1]), 'It should only consume the one used.');
        self::assertSame(9, $user->countUnusedRecoveryCodes());
    }

    /**
     * A seed that will not decrypt - because APP_SECRET was rotated, or the row was
     * corrupted - must degrade to password-only rather than locking the member out.
     *
     * Reporting "enabled" while no configuration can be built makes the two-factor
     * provider throw during sign-in, and that exception fires before the challenge
     * renders, so not even a recovery code can be entered.
     */
    public function testAnUndecryptableSeedDoesNotLockTheMemberOut(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);
        $this->totp()->confirm($user, $this->codeFor($secret));
        self::assertTrue($user->isTotpAuthenticationEnabled());

        // What a rotated APP_SECRET leaves behind: ciphertext nothing can open.
        $user->setTotpSecret(base64_encode(random_bytes(64)));
        $this->em()->flush();
        $this->em()->clear();

        $reloaded = $this->user('Member');
        self::assertNull($this->totp()->secretFor($reloaded), 'The seed should be unreadable.');
        self::assertFalse($reloaded->isTotpAuthenticationEnabled(), 'It still claims to be on.');
        self::assertNull($reloaded->getTotpAuthenticationConfiguration());

        // And the member can still get in.
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['username' => 'Member', 'password' => TestWorld::PASSWORD]);

        $this->client->request('GET', '/');
        self::assertStringContainsString('Logout', $this->client->getResponse()->getContent());
    }

    public function testDisablingClearsEverything(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);
        $this->totp()->confirm($user, $this->codeFor($secret));

        $this->totp()->disable($user);

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpSecret());
        self::assertSame(0, $user->countUnusedRecoveryCodes());
    }

    // ------------------------------------------------------------------
    // Code checking
    // ------------------------------------------------------------------

    public function testTheCurrentCodeVerifiesAndOthersDoNot(): void
    {
        $secret = $this->totp()->beginSetup($this->user('Member'));

        self::assertTrue($this->totp()->verify($secret, $this->codeFor($secret)));

        foreach (['000000', '12345', '1234567', 'abcdef', '', '   '] as $wrong) {
            self::assertFalse($this->totp()->verify($secret, $wrong), \sprintf('"%s" was accepted.', $wrong));
        }
    }

    public function testACodeFromAnotherSeedIsRejected(): void
    {
        $mine = $this->totp()->beginSetup($this->user('Member'));
        $theirs = $this->totp()->beginSetup($this->user('Other'));

        self::assertFalse($this->totp()->verify($mine, $this->codeFor($theirs)));
    }

    public function testACodeFromLongAgoIsRejected(): void
    {
        $secret = $this->totp()->beginSetup($this->user('Member'));

        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(User::TOTP_PERIOD);
        $totp->setDigits(User::TOTP_DIGITS);
        $totp->setDigest(User::TOTP_ALGORITHM);

        self::assertFalse($this->totp()->verify($secret, $totp->at(time() - 3600)));
    }

    // ------------------------------------------------------------------
    // The provisioning details an app needs
    // ------------------------------------------------------------------

    public function testTheProvisioningUriCarriesWhatAnAppNeeds(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);

        $uri = $this->totp()->provisioningUri($user, $secret);

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret='.$secret, $uri);

        // Label and issuer, so the app shows which account this belongs to.
        self::assertStringContainsString('Member', urldecode($uri));
        self::assertStringContainsString('issuer=', $uri);

        // digits and period are absent on purpose: they are the otpauth defaults,
        // and the library omits anything that matches a default. An app reading
        // this gets 6 digits over 30 seconds either way.
        self::assertStringNotContainsString('digits=', $uri);
        self::assertStringNotContainsString('period=', $uri);
        self::assertSame(6, User::TOTP_DIGITS);
        self::assertSame(30, User::TOTP_PERIOD);
    }

    public function testTheQrCodeIsAPng(): void
    {
        $user = $this->user('Member');
        $secret = $this->totp()->beginSetup($user);

        $png = $this->totp()->qrCode($user, $secret);

        self::assertStringStartsWith("\x89PNG", $png);
        self::assertNotFalse(imagecreatefromstring($png));
    }

    // ------------------------------------------------------------------
    // The pages
    // ------------------------------------------------------------------

    public function testTheManagementAndSetupPagesRender(): void
    {
        $this->signInAs('Member');

        $this->assertPageLoads('/profile/authenticator');
        $crawler = $this->assertPageLoads('/profile/authenticator/setup');

        self::assertCount(1, $crawler->filter('.totp-secret'), 'The key is not shown for manual entry.');
        self::assertCount(1, $crawler->filter('img[src$="qr.png"]'), 'There is no QR code to scan.');
    }

    public function testTheSetupPageIsLinkedFromTheProfile(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/edit');

        self::assertCount(1, $crawler->filter('a[href$="/profile/authenticator"]'));
    }

    public function testGuestsCannotReachAnyOfIt(): void
    {
        foreach (['/profile/authenticator', '/profile/authenticator/setup'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403], $uri);
        }
    }

    public function testItCanBeTurnedOffBoardWide(): void
    {
        $this->signInAs('Member');

        $config = $this->container()->get(BoardConfigRepository::class)->get();
        $config->setTotpEnabled(false);
        $this->em()->flush();

        $crawler = $this->assertPageLoads('/profile/authenticator');
        self::assertStringContainsString('disabled on this board', $crawler->text());

        $this->client->request('GET', '/profile/authenticator/setup');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Signing in
    // ------------------------------------------------------------------

    public function testSigningInWithoutASecondFactorIsUnaffected(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['username' => 'Member', 'password' => TestWorld::PASSWORD]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->client->request('GET', '/');
        self::assertStringContainsString('Logout', $this->client->getResponse()->getContent());
    }

    /**
     * With a second factor set up, a correct password must not be enough: the
     * member holds a two-factor token and member pages stay closed until they
     * answer the challenge.
     */
    public function testAPasswordAloneNoLongerSignsYouIn(): void
    {
        $this->enableFor('Member');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['username' => 'Member', 'password' => TestWorld::PASSWORD]);

        $this->client->request('GET', '/messages');
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode(), 'A password alone reached a member page.');

        $crawler = $this->client->request('GET', '/2fa');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Two-step sign-in', $crawler->text());
    }

    public function testTheChallengeCompletesWithACorrectCode(): void
    {
        $secret = $this->enableFor('Member');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['username' => 'Member', 'password' => TestWorld::PASSWORD]);

        $this->client->request('GET', '/2fa');
        $this->client->submitForm('Sign in', ['_auth_code' => $this->codeFor($secret)]);

        $this->client->request('GET', '/');
        self::assertStringContainsString('Logout', $this->client->getResponse()->getContent());
    }

    public function testTheChallengeRefusesAWrongCode(): void
    {
        $this->enableFor('Member');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['username' => 'Member', 'password' => TestWorld::PASSWORD]);

        $this->client->request('GET', '/2fa');
        $this->client->submitForm('Sign in', ['_auth_code' => '000000']);

        $this->client->request('GET', '/messages');
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode(), 'A wrong code got in.');
    }

    public function testARecoveryCodeCompletesTheChallengeAndIsThenSpent(): void
    {
        $codes = [];
        $this->enableFor('Member', $codes);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['username' => 'Member', 'password' => TestWorld::PASSWORD]);

        $this->client->request('GET', '/2fa');
        $this->client->submitForm('Sign in', ['_auth_code' => $codes[0]]);

        $this->client->request('GET', '/');
        self::assertStringContainsString('Logout', $this->client->getResponse()->getContent());

        $this->em()->clear();
        self::assertSame(9, $this->user('Member')->countUnusedRecoveryCodes(), 'The code was not consumed.');
        self::assertFalse($this->user('Member')->isBackupCode($codes[0]));
    }

    // ------------------------------------------------------------------

    private function totp(): TotpService
    {
        return $this->container()->get(TotpService::class);
    }

    /** Turns it on for a member and returns the seed. */
    private function enableFor(string $name, ?array &$codes = null): string
    {
        $user = $this->user($name);
        $secret = $this->totp()->beginSetup($user);
        $codes = $this->totp()->confirm($user, $this->codeFor($secret));
        $this->em()->flush();

        return $secret;
    }

    /** The code an authenticator app would be showing right now. */
    private function codeFor(string $secret): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(User::TOTP_PERIOD);
        $totp->setDigits(User::TOTP_DIGITS);
        $totp->setDigest(User::TOTP_ALGORITHM);

        return $totp->now();
    }
}
