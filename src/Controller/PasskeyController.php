<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Passkey;
use App\Repository\UserRepository;
use App\Service\PasskeyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registering and managing passkeys, plus the options endpoint the login page calls.
 *
 * The ceremonies themselves live in PasskeyService; this only moves JSON between the
 * browser and that service, and enforces who may do what.
 */
final class PasskeyController extends AbstractBoardController
{
    // ------------------------------------------------------------------
    // Managing your own passkeys
    // ------------------------------------------------------------------

    #[Route('/profile/passkeys', name: 'app_passkeys', methods: ['GET'])]
    public function index(PasskeyService $passkeys): Response
    {
        $user = $this->requireBoardUser();

        return $this->render('profile/passkeys.html.twig', [
            'passkeys' => $passkeys->listFor($user),
            'enabled' => $passkeys->isEnabled(),
            'hasPassword' => '' !== $user->getPassword(),
        ]);
    }

    /**
     * Creation options for a new passkey. The challenge is held in the session, so
     * this must be called immediately before the browser ceremony.
     */
    #[Route('/profile/passkeys/options', name: 'app_passkey_register_options', methods: ['POST'])]
    public function registerOptions(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'passkey-register');

        if (!$passkeys->isEnabled()) {
            return $this->json(['error' => 'Passkeys are disabled on this board.'], Response::HTTP_FORBIDDEN);
        }

        // Pre-encoded by the library serializer; JsonResponse must not re-encode it.
        return new JsonResponse(
            $passkeys->serializeOptions($passkeys->startRegistration($user, $request)),
            json: true,
        );
    }

    /**
     * Verifies the authenticator's registration response and stores the credential.
     */
    #[Route('/profile/passkeys/register', name: 'app_passkey_register', methods: ['POST'])]
    public function register(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'passkey-register');

        if (!$passkeys->isEnabled()) {
            return $this->json(['error' => 'Passkeys are disabled on this board.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !isset($payload['credential'])) {
            return $this->json(['error' => 'Malformed request.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $passkey = $passkeys->finishRegistration(
                $user,
                $request,
                json_encode($payload['credential'], \JSON_THROW_ON_ERROR),
                (string) ($payload['name'] ?? ''),
            );
        } catch (\Throwable $e) {
            // The reason a ceremony failed is diagnostic, not secret - it is the
            // member's own browser talking about the member's own device.
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $this->addFlash('success', \sprintf('Passkey "%s" registered.', $passkey->getName()));

        return $this->json(['ok' => true, 'redirect' => $this->generateUrl('app_passkeys')]);
    }

    #[Route('/profile/passkeys/{id<\d+>}/rename', name: 'app_passkey_rename', methods: ['POST'])]
    public function rename(Passkey $passkey, Request $request, PasskeyService $passkeys): Response
    {
        $this->assertOwnership($passkey);
        $this->assertCsrf($request, 'passkey'.$passkey->getId());

        $name = trim((string) $request->request->get('name'));
        if ('' !== $name) {
            $passkey->setName(mb_substr($name, 0, 100));
            $this->container->get('doctrine')->getManager()->flush();
            $this->addFlash('success', 'Passkey renamed.');
        }

        return $this->redirectToRoute('app_passkeys');
    }

    #[Route('/profile/passkeys/{id<\d+>}/delete', name: 'app_passkey_delete', methods: ['POST'])]
    public function delete(Passkey $passkey, Request $request, PasskeyService $passkeys): Response
    {
        $this->assertOwnership($passkey);
        $this->assertCsrf($request, 'passkey'.$passkey->getId());

        // Refuse to remove the only way into an account that has no password.
        if ($passkeys->isLastMeansOfAccess($passkey)) {
            $this->addFlash(
                'error',
                'That is your only passkey and your account has no password, so removing it '
                .'would lock you out. Set a password first.',
            );

            return $this->redirectToRoute('app_passkeys');
        }

        $name = $passkey->getName();
        $passkeys->remove($passkey);
        $this->addFlash('success', \sprintf('Passkey "%s" removed.', $name));

        return $this->redirectToRoute('app_passkeys');
    }

    // ------------------------------------------------------------------
    // Signing in
    // ------------------------------------------------------------------

    /**
     * Request options for a passkey sign-in.
     *
     * A username may be supplied for authenticators that cannot store discoverable
     * credentials. An unknown username returns options with an empty credential list
     * rather than an error, so this cannot be used to test whether an account exists.
     */
    #[Route('/login/passkey/options', name: 'app_passkey_login_options', methods: ['POST'])]
    public function loginOptions(Request $request, PasskeyService $passkeys, UserRepository $users): JsonResponse
    {
        if (!$passkeys->isEnabled()) {
            return $this->json(['error' => 'Passkeys are disabled on this board.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        $username = \is_array($payload) ? trim((string) ($payload['username'] ?? '')) : '';

        $user = '' !== $username ? $users->findOneByUsername($username) : null;

        return new JsonResponse(
            $passkeys->serializeOptions($passkeys->startAuthentication($request, $user)),
            json: true,
        );
    }

    /**
     * Declared so the router resolves the path; the request never reaches this body.
     *
     * PasskeyAuthenticator claims POST requests to this path from the firewall, which
     * runs *after* the router on kernel.request. Without a route the router raises a
     * 404 first and the authenticator is never consulted - the same reason a
     * form_login check_path has to be a real route.
     */
    #[Route('/login/passkey/verify', name: 'app_passkey_login_verify', methods: ['POST'])]
    public function loginVerify(): never
    {
        throw new \LogicException('This is intercepted by App\Security\PasskeyAuthenticator.');
    }

    private function assertOwnership(Passkey $passkey): void
    {
        if ($passkey->getUser() !== $this->requireBoardUser()) {
            throw $this->createAccessDeniedException('That passkey is not yours.');
        }
    }
}
