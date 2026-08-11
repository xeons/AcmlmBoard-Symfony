<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Form login.
 *
 * The original authenticated by setting two cookies that never expired: `loguserid`
 * with the raw user id, and `logpassword` with the password run through shenc() - a
 * hand-rolled reversible byte shuffle. Every request decoded that cookie back to the
 * plaintext password, md5'd it, and compared. So the password travelled on every
 * request in a trivially reversible form, and anyone who read a cookie recovered the
 * password itself rather than merely a session.
 *
 * This is a signed session plus Symfony's remember-me token, and the plaintext
 * password exists only for the duration of the login request.
 */
final class BoardAuthenticator extends AbstractLoginFormAuthenticator
{
    public const LOGIN_ROUTE = 'app_login';

    use TargetPathTrait;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function authenticate(Request $request): Passport
    {
        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        $badges = [new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token'))];

        if ($request->request->getBoolean('remember_me')) {
            $badges[] = new RememberMeBadge();
        }

        return new Passport(
            new UserBadge($username),
            new PasswordCredentials($password),
            $badges,
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // The password was right but a second factor is still owed. The token has
        // already been swapped for a two-factor one by this point, so send them
        // straight to the challenge rather than to a page that will only bounce
        // them there. The stored target path is left alone, so they still land
        // where they were heading once they finish.
        if ($token instanceof TwoFactorTokenInterface) {
            return new RedirectResponse($this->urlGenerator->generate('app_2fa_login'));
        }

        if ($target = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($target);
        }

        $user = $token->getUser();

        // A brand-new account lands on the FAQ, as the original did.
        if ($user instanceof User && 0 === $user->getPosts() && null === $user->getLastPostAt()) {
            return new RedirectResponse($this->urlGenerator->generate('app_faq'));
        }

        return new RedirectResponse($this->urlGenerator->generate('app_index'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->getLoginUrl($request));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
