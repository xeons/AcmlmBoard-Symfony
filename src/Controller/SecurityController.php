<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\RegistrationRequestType;
use App\Form\VerifyRegistrationType;
use App\Service\RegistrationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Login, logout, and the two-step registration flow.
 */
final class SecurityController extends AbstractBoardController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_index');
        }

        return $this->render('security/login.html.twig', [
            'lastUsername' => $authUtils->getLastUsername(),
            'error' => $authUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Intercepted by the firewall; this body never executes. Declared POST-only so
     * logout cannot be triggered by a link on a third-party page.
     */
    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This is intercepted by the logout key on the firewall.');
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        RegistrationService $registration,
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        if (!$registration->registrationOpenFor($this->boardUser())) {
            return $this->render('security/register_closed.html.twig', [
                'reason' => $registration->registrationClosedReason($this->boardUser()),
            ]);
        }

        $form = $this->createForm(RegistrationRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Per-IP cap on registration attempts, which the original attempted with
            // a commented-out COUNT and then abandoned.
            $limiter = $registrationLimiter->create($this->clientIp($request) ?? 'unknown');
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', 'Too many registration attempts from your address. Please try again tomorrow.');

                return $this->redirectToRoute('app_register');
            }

            $data = $form->getData();
            $error = $registration->requestVerification(
                (string) $data['username'],
                (string) $data['email'],
                $this->clientIp($request),
            );

            if (null !== $error) {
                $this->addFlash('error', $error);

                return $this->redirectToRoute('app_register');
            }

            return $this->render('security/register_sent.html.twig', [
                'username' => $data['username'],
                'email' => $data['email'],
            ]);
        }

        return $this->render('security/register.html.twig', ['form' => $form]);
    }

    /**
     * Redeems the emailed code. The username and code arrive as query parameters
     * from the email link and pre-fill the form; the password is always typed here.
     */
    #[Route('/register/verify', name: 'app_register_verify', methods: ['GET', 'POST'])]
    public function verify(
        Request $request,
        RegistrationService $registration,
        #[MapQueryParameter] ?string $username = null,
        #[MapQueryParameter] ?string $code = null,
    ): Response {
        $form = $this->createForm(VerifyRegistrationType::class, [
            'username' => $username,
            'code' => $code,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            [$user, $error] = $registration->verify(
                (string) $data['username'],
                (string) $data['code'],
                (string) $data['password'],
            );

            if (null !== $error) {
                $this->addFlash('error', $error);
            } else {
                $this->addFlash('success', \sprintf(
                    'Your account is verified, %s. You can now log in.',
                    $user?->getUsername() ?? '',
                ));

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/verify.html.twig', ['form' => $form]);
    }
}
