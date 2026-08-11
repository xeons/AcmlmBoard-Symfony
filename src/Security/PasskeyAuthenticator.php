<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\PasskeyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Signs a member in with a passkey.
 *
 * This is a SelfValidatingPassport because the credential check has already happened
 * - cryptographically, against a challenge this server issued and an origin the
 * authenticator verified. There is no password to hand to a hasher; the proof is the
 * signature.
 *
 * The route is deliberately outside the form-login authenticator's path so the two
 * do not compete: BoardAuthenticator handles POST /login, this handles
 * POST /login/passkey/verify.
 */
final class PasskeyAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_PATH = '/login/passkey/verify';

    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && self::LOGIN_PATH === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->passkeys->isEnabled()) {
            throw new CustomUserMessageAuthenticationException('Passkeys are disabled on this board.');
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !isset($payload['credential'])) {
            throw new CustomUserMessageAuthenticationException('Malformed sign-in request.');
        }

        try {
            $passkey = $this->passkeys->finishAuthentication(
                $request,
                json_encode($payload['credential'], \JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), previous: $e);
        }

        $user = $passkey->getUser();

        // A banned account can still read the board, so it may still sign in - the
        // voters decide what it can do once inside, exactly as with a password.
        $badges = [new RememberMeBadge()];

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): User => $user),
            $badges,
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName)
            ?? $this->urls->generate('app_index');

        // The browser drives this over fetch(), so hand back a destination rather
        // than a redirect it would have to follow itself.
        return new JsonResponse(['ok' => true, 'redirect' => $target]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
