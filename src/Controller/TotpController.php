<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TotpService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Setting up and removing an authenticator app.
 *
 * The seed is generated and stored when the page is opened, but nothing is required
 * at sign-in until a code has been entered successfully - so abandoning the page
 * halfway leaves the account exactly as it was rather than locking it.
 */
final class TotpController extends AbstractBoardController
{
    /** Where the freshly generated seed waits between opening the page and confirming. */
    private const SETUP_SESSION_KEY = 'totp_setup_secret';

    #[Route('/profile/authenticator', name: 'app_totp', methods: ['GET'])]
    public function index(TotpService $totp): Response
    {
        $user = $this->requireBoardUser();

        return $this->render('profile/authenticator.html.twig', [
            'enabled' => $totp->isEnabledOnBoard(),
            'active' => $user->isTotpAuthenticationEnabled(),
            'recoveryCodesLeft' => $user->countUnusedRecoveryCodes(),
            'confirmedAt' => $user->getTotpConfirmedAt(),
        ]);
    }

    #[Route('/profile/authenticator/setup', name: 'app_totp_setup', methods: ['GET'])]
    public function setup(Request $request, TotpService $totp): Response
    {
        $user = $this->requireBoardUser();

        if (!$totp->isEnabledOnBoard()) {
            throw $this->createAccessDeniedException('Authenticator apps are disabled on this board.');
        }

        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_totp');
        }

        // A new seed each time the page is opened, so a half-finished attempt is
        // never left usable.
        $secret = $totp->beginSetup($user);
        $request->getSession()->set(self::SETUP_SESSION_KEY, $secret);

        return $this->render('profile/authenticator_setup.html.twig', [
            'secret' => $totp->formatSecret($secret),
            'uri' => $totp->provisioningUri($user, $secret),
        ]);
    }

    /** The QR image, served separately so the seed never appears in a page URL. */
    #[Route('/profile/authenticator/qr.png', name: 'app_totp_qr', methods: ['GET'])]
    public function qr(Request $request, TotpService $totp): Response
    {
        $user = $this->requireBoardUser();
        $secret = $request->getSession()->get(self::SETUP_SESSION_KEY);

        if (!\is_string($secret) || '' === $secret) {
            throw $this->createNotFoundException('There is no setup in progress.');
        }

        $response = new Response($totp->qrCode($user, $secret), Response::HTTP_OK, ['Content-Type' => 'image/png']);
        $response->setPrivate();
        $response->setMaxAge(0);

        return $response;
    }

    #[Route('/profile/authenticator/confirm', name: 'app_totp_confirm', methods: ['POST'])]
    public function confirm(Request $request, TotpService $totp): Response
    {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'totp-setup');

        if (!$totp->isEnabledOnBoard()) {
            throw $this->createAccessDeniedException('Authenticator apps are disabled on this board.');
        }

        $codes = $totp->confirm($user, (string) $request->request->get('code'));

        if (null === $codes) {
            $this->addFlash('error', 'That code did not match. Check your device\'s clock and try again.');

            return $this->redirectToRoute('app_totp_setup');
        }

        $request->getSession()->remove(self::SETUP_SESSION_KEY);

        return $this->render('profile/authenticator_codes.html.twig', [
            'codes' => $codes,
            'firstTime' => true,
        ]);
    }

    #[Route('/profile/authenticator/recovery-codes', name: 'app_totp_recovery', methods: ['POST'])]
    public function regenerate(Request $request, TotpService $totp): Response
    {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'totp-manage');

        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_totp');
        }

        return $this->render('profile/authenticator_codes.html.twig', [
            'codes' => $totp->regenerateRecoveryCodes($user),
            'firstTime' => false,
        ]);
    }

    /**
     * Turning it off asks for a current code, so somebody who walks up to an
     * unlocked browser cannot quietly remove the second factor.
     */
    #[Route('/profile/authenticator/disable', name: 'app_totp_disable', methods: ['POST'])]
    public function disable(Request $request, TotpService $totp): Response
    {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'totp-manage');

        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_totp');
        }

        $code = (string) $request->request->get('code');
        $secret = $totp->secretFor($user);

        if (null === $secret || (!$totp->verify($secret, $code) && !$user->isBackupCode($code))) {
            $this->addFlash('error', 'That code did not match, so nothing has changed.');

            return $this->redirectToRoute('app_totp');
        }

        $totp->disable($user);
        $this->addFlash('success', 'Your authenticator app has been removed from this account.');

        return $this->redirectToRoute('app_totp');
    }
}
