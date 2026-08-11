<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Passkey;
use App\Entity\User;
use App\Repository\BoardConfigRepository;
use App\Repository\PasskeyRepository;
use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA;
use Cose\Algorithm\Signature\EdDSA;
use Cose\Algorithm\Signature\RSA;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Both WebAuthn ceremonies: registering a passkey, and signing in with one.
 *
 * Why this is worth having on a forum: the original board's entire authentication
 * story was an md5 of a password, sent on every request in a reversible cookie. Even
 * with that replaced by a modern hash and a session, a password is still a shared
 * secret that can be phished, reused across sites, and leaked in someone else's
 * breach. A passkey is an origin-bound key pair whose private half never leaves the
 * member's device - it cannot be phished, because the authenticator refuses to sign
 * for the wrong origin, and there is nothing on the server worth stealing.
 *
 * Passwords remain: passkeys are strictly additive, and a member can use either.
 */
final class PasskeyService
{
    /** How long a challenge stays valid. */
    private const TIMEOUT_MS = 120_000;

    private const SESSION_CREATION = 'passkey.creation_options';
    private const SESSION_REQUEST = 'passkey.request_options';

    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly PasskeyRepository $passkeys,
        private readonly BoardConfigRepository $config,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->get()->isPasskeysEnabled();
    }

    // ------------------------------------------------------------------
    // Registration ceremony
    // ------------------------------------------------------------------

    /**
     * Options for creating a new passkey, stashed in the session so the response can
     * be checked against the challenge that produced it.
     */
    public function startRegistration(User $user, Request $request): PublicKeyCredentialCreationOptions
    {
        $this->ensureHandle($user);

        $options = PublicKeyCredentialCreationOptions::create(
            rp: new PublicKeyCredentialRpEntity($this->relyingPartyName(), $this->relyingPartyId($request)),
            user: PublicKeyCredentialUserEntity::create(
                $user->getUsername(),
                (string) $user->getWebauthnHandle(),
                $user->getUsername(),
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: $this->supportedAlgorithms(),
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                // "preferred", not "required": a security key that cannot store a
                // discoverable credential should still be usable, it just means the
                // member types their username first.
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            ),
            // No attestation: the board has no use for knowing the authenticator's
            // make and model, and asking for it prompts the member for consent on
            // some platforms for no benefit.
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            // Stops the same authenticator registering twice for one account.
            excludeCredentials: $this->descriptorsFor($user),
            timeout: self::TIMEOUT_MS,
        );

        $request->getSession()->set(self::SESSION_CREATION, $this->serializer()->serialize($options, 'json'));

        return $options;
    }

    /**
     * Verifies the authenticator's response and stores the credential.
     *
     * @throws \Throwable when the response does not check out
     */
    public function finishRegistration(User $user, Request $request, string $responseJson, string $name): Passkey
    {
        $stored = $request->getSession()->get(self::SESSION_CREATION);
        if (!\is_string($stored)) {
            throw new \RuntimeException('No passkey registration is in progress. Please start again.');
        }

        // Single use: a challenge must never be replayable.
        $request->getSession()->remove(self::SESSION_CREATION);

        $options = $this->serializer()->deserialize($stored, PublicKeyCredentialCreationOptions::class, 'json');
        $credential = $this->serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');

        $response = $credential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('That is not a registration response.');
        }

        $record = AuthenticatorAttestationResponseValidator::create(
            $this->ceremonyFactory($request)->creationCeremony(),
        )->check($response, $options, $this->relyingPartyId($request));

        $encodedId = $this->encodeCredentialId($record->publicKeyCredentialId);

        if (null !== $this->passkeys->findByCredentialId($encodedId)) {
            throw new \RuntimeException('That passkey is already registered.');
        }

        $passkey = new Passkey(
            $user,
            $encodedId,
            $this->serializer()->serialize($record, 'json'),
            '' !== trim($name) ? mb_substr(trim($name), 0, 100) : $this->defaultName($record),
        );
        $passkey->setAaguid($record->aaguid->__toString());
        $passkey->setBackedUp($record->backupStatus);
        $passkey->recordUse($record->counter);

        $this->em->persist($passkey);
        $this->em->flush();

        return $passkey;
    }

    // ------------------------------------------------------------------
    // Authentication ceremony
    // ------------------------------------------------------------------

    /**
     * Options for signing in.
     *
     * With no username, allowCredentials is left empty and the authenticator offers
     * whichever discoverable credential it holds for this site - the "just tap the
     * key" flow. With a username, the list is narrowed to that member's credentials
     * so a security key without discoverable-credential support still works.
     *
     * The username path deliberately does not reveal whether the account exists: an
     * unknown name yields an empty list, exactly like a known one with no passkeys.
     */
    public function startAuthentication(Request $request, ?User $user = null): PublicKeyCredentialRequestOptions
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->relyingPartyId($request),
            allowCredentials: null !== $user ? $this->descriptorsFor($user) : [],
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: self::TIMEOUT_MS,
        );

        $request->getSession()->set(self::SESSION_REQUEST, $this->serializer()->serialize($options, 'json'));

        return $options;
    }

    /**
     * Verifies an assertion and returns the passkey it belongs to.
     *
     * @throws \Throwable when the assertion does not check out
     */
    public function finishAuthentication(Request $request, string $responseJson): Passkey
    {
        $stored = $request->getSession()->get(self::SESSION_REQUEST);
        if (!\is_string($stored)) {
            throw new \RuntimeException('No sign-in is in progress. Please start again.');
        }

        $request->getSession()->remove(self::SESSION_REQUEST);

        $options = $this->serializer()->deserialize($stored, PublicKeyCredentialRequestOptions::class, 'json');
        $credential = $this->serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');

        $response = $credential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('That is not a sign-in response.');
        }

        $encodedId = $this->encodeCredentialId($credential->rawId);
        $passkey = $this->passkeys->findByCredentialId($encodedId);

        if (null === $passkey) {
            throw new \RuntimeException('That passkey is not registered here.');
        }

        $record = $this->serializer()->deserialize(
            $passkey->getCredentialSource(),
            PublicKeyCredentialSource::class,
            'json',
        );

        // The user handle the authenticator returns must match the account the
        // credential is filed under, or a credential could be replayed against a
        // different member.
        $userHandle = $response->userHandle;
        $expected = $passkey->getUser()->getWebauthnHandle();
        if (null !== $userHandle && '' !== $userHandle && $userHandle !== $expected) {
            throw new \RuntimeException('That passkey does not belong to this account.');
        }

        $updated = AuthenticatorAssertionResponseValidator::create(
            $this->ceremonyFactory($request)->requestCeremony(),
        )->check($record, $response, $options, $this->relyingPartyId($request), $expected);

        // Persist the new signature counter - the clone-detection signal is
        // worthless if the stored counter never moves.
        $passkey->recordUse($updated->counter);
        $passkey->setCredentialSource($this->serializer()->serialize($updated, 'json'));
        $this->em->flush();

        return $passkey;
    }

    // ------------------------------------------------------------------
    // Management
    // ------------------------------------------------------------------

    /**
     * Serialises options for the browser.
     *
     * This must go through the library's serializer rather than plain json_encode:
     * challenges and credential ids are raw binary, which is not valid UTF-8 and
     * makes json_encode fail outright. The serializer base64url-encodes them, which
     * is also exactly what the JS expects to decode back into ArrayBuffers.
     */
    public function serializeOptions(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
    {
        return $this->serializer()->serialize($options, 'json');
    }

    /** @return list<Passkey> */
    public function listFor(User $user): array
    {
        return $this->passkeys->findForUser($user);
    }

    public function countFor(User $user): int
    {
        return \count($this->passkeys->findForUser($user));
    }

    public function remove(Passkey $passkey): void
    {
        $this->em->remove($passkey);
        $this->em->flush();
    }

    /**
     * Whether removing this passkey would leave the member unable to sign in.
     *
     * An account with a password can always fall back to it. One that has only ever
     * used passkeys would be locked out by deleting the last one, so the UI refuses.
     */
    public function isLastMeansOfAccess(Passkey $passkey): bool
    {
        $user = $passkey->getUser();

        return '' === $user->getPassword() && 1 === $this->passkeys->countForUser($user);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The relying party id: the domain the credential is bound to.
     *
     * Bare host, never including the port - an authenticator will reject an rpId
     * with one, which is the failure people hit when testing on localhost:8080.
     */
    private function relyingPartyId(Request $request): string
    {
        return $request->getHost();
    }

    private function relyingPartyName(): string
    {
        return $this->config->get()->getBoardName();
    }

    private function ceremonyFactory(Request $request): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAlgorithmManager($this->algorithmManager());

        // The origin the browser reports must be exactly this one. This is what makes
        // a passkey unphishable: a lookalike domain cannot produce a valid assertion
        // because the authenticator signs the origin it actually saw.
        $factory->setAllowedOrigins([$request->getSchemeAndHttpHost()]);

        return $factory;
    }

    private function algorithmManager(): AlgorithmManager
    {
        // Ed25519 and ES256 cover essentially every authenticator in use; the RSA
        // entries are there for older security keys.
        //
        // Note add(), not create([...]): Manager::create() takes no arguments, so
        // passing an array yields an *empty* manager. That fails silently at
        // registration - a "none" attestation verifies no signature - and only
        // surfaces at the first sign-in, as "Unsupported algorithm".
        return AlgorithmManager::create()->add(
            EdDSA\Ed25519::create(),
            ECDSA\ES256::create(),
            ECDSA\ES384::create(),
            ECDSA\ES512::create(),
            RSA\RS256::create(),
            RSA\PS256::create(),
        );
    }

    /** @return list<PublicKeyCredentialParameters> */
    private function supportedAlgorithms(): array
    {
        return array_map(
            static fn (int $alg): PublicKeyCredentialParameters => PublicKeyCredentialParameters::create(
                'public-key',
                $alg,
            ),
            [-8, -7, -35, -36, -257, -37],
        );
    }

    /** @return list<PublicKeyCredentialDescriptor> */
    private function descriptorsFor(User $user): array
    {
        return array_map(
            fn (Passkey $passkey): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                'public-key',
                $this->decodeCredentialId($passkey->getCredentialId()),
            ),
            $this->passkeys->findForUser($user),
        );
    }

    private function ensureHandle(User $user): void
    {
        if (null === $user->getWebauthnHandle()) {
            $user->setWebauthnHandle(bin2hex(random_bytes(24)));
            $this->em->flush();
        }
    }

    private function defaultName(CredentialRecord $record): string
    {
        return match (true) {
            true === $record->backupStatus => 'Synced passkey',
            [] !== $record->transports && \in_array('internal', $record->transports, true) => 'This device',
            [] !== $record->transports && \in_array('usb', $record->transports, true) => 'Security key',
            default => 'Passkey',
        };
    }

    /** base64url, so credential ids survive a URL and a unique index unchanged. */
    private function encodeCredentialId(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decodeCredentialId(string $encoded): string
    {
        $padded = str_pad($encoded, (int) (4 * ceil(\strlen($encoded) / 4)), '=');

        return (string) base64_decode(strtr($padded, '-_', '+/'), true);
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(
            \Webauthn\AttestationStatement\AttestationStatementSupportManager::create(),
        ))->create();
    }
}
