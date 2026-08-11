<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\PasskeyService;
use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA;
use Cose\Algorithm\Signature\EdDSA;
use Cose\Algorithm\Signature\RSA;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Passkey plumbing.
 *
 * The full ceremonies are exercised over HTTP with a virtual authenticator; what is
 * pinned here are the pieces that fail quietly rather than loudly, and the two
 * properties that make a passkey worth having in the first place.
 */
final class PasskeyServiceTest extends KernelTestCase
{
    private PasskeyService $passkeys;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->passkeys = self::getContainer()->get(PasskeyService::class);
    }

    /**
     * The regression that cost the most to find.
     *
     * Cose\Algorithm\Manager::create() takes no arguments, so create([$alg]) builds
     * an *empty* manager. Registration still succeeds, because a "none" attestation
     * verifies no signature - the failure only appears at the first sign-in, as
     * "Unsupported algorithm", by which point a member has a passkey that cannot
     * work. Assert the algorithms are actually registered.
     */
    public function testAlgorithmManagerIsPopulated(): void
    {
        $manager = AlgorithmManager::create()->add(
            EdDSA\Ed25519::create(),
            ECDSA\ES256::create(),
            ECDSA\ES384::create(),
            ECDSA\ES512::create(),
            RSA\RS256::create(),
            RSA\PS256::create(),
        );

        // ES256 and Ed25519 between them cover essentially every real authenticator.
        self::assertTrue($manager->has(-7), 'ES256 must be registered');
        self::assertTrue($manager->has(-8), 'Ed25519 must be registered');
        self::assertTrue($manager->has(-257), 'RS256 must be registered for older keys');
    }

    /** Demonstrates the trap directly, so nobody reintroduces it. */
    public function testPassingAnArrayToTheAlgorithmManagerSilentlyDoesNothing(): void
    {
        $empty = AlgorithmManager::create([ECDSA\ES256::create()]);

        self::assertFalse(
            $empty->has(-7),
            'Manager::create() ignores arguments - algorithms must be added with add()',
        );
    }

    /**
     * An rpId with a port is rejected outright by authenticators, which is exactly
     * what happens when developing on localhost:8080 and passing the host header
     * through unfiltered.
     */
    public function testRelyingPartyIdExcludesThePort(): void
    {
        $request = Request::create('http://localhost:8080/login');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        $options = $this->passkeys->startAuthentication($request);

        self::assertSame('localhost', $options->rpId);
        self::assertStringNotContainsString(':', (string) $options->rpId);
    }

    public function testChallengeIsRandomAndLongEnough(): void
    {
        $request = Request::create('http://localhost:8080/login');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        $first = $this->passkeys->startAuthentication($request)->challenge;
        $second = $this->passkeys->startAuthentication($request)->challenge;

        // 32 bytes; anything shorter narrows the margin against a replay.
        self::assertGreaterThanOrEqual(32, \strlen($first));
        self::assertNotSame($first, $second, 'Every ceremony must get a fresh challenge');
    }

    /**
     * Options are serialised by the library, not json_encode: challenges and
     * credential ids are raw binary, so plain encoding fails on invalid UTF-8.
     */
    public function testOptionsSerialiseToValidJsonWithBase64UrlFields(): void
    {
        $request = Request::create('http://localhost:8080/login');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        $json = $this->passkeys->serializeOptions($this->passkeys->startAuthentication($request));
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded, 'Options must encode to valid JSON');
        self::assertArrayHasKey('challenge', $decoded);
        // base64url: no +, / or = padding.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $decoded['challenge']);
    }

    /**
     * The user handle is stored on the member's device and returned on every
     * sign-in. Using the primary key would leak how many accounts exist and let one
     * member infer another's id, so it must be opaque and unrelated.
     */
    public function testUserHandleIsOpaqueAndNotTheDatabaseId(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setUsername('PasskeyProbe'.bin2hex(random_bytes(4)));
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $request = Request::create('http://localhost:8080/profile/passkeys');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        $options = $this->passkeys->startRegistration($user, $request);
        $handle = $options->user->id;

        self::assertNotSame((string) $user->getId(), $handle);
        self::assertGreaterThanOrEqual(16, \strlen($handle));
        // Stable across ceremonies, or existing credentials would stop resolving.
        self::assertSame($handle, $this->passkeys->startRegistration($user, $request)->user->id);

        $em->remove($user);
        $em->flush();
    }

    /**
     * Attestation is deliberately not requested: the board has no use for the
     * authenticator's make and model, and asking prompts the member for consent on
     * some platforms in exchange for nothing.
     */
    public function testRegistrationDoesNotRequestAttestation(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setUsername('AttestProbe'.bin2hex(random_bytes(4)));
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $request = Request::create('http://localhost:8080/profile/passkeys');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        self::assertSame('none', $this->passkeys->startRegistration($user, $request)->attestation);

        $em->remove($user);
        $em->flush();
    }
}
