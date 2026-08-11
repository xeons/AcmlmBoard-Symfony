<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BoardConfig;
use App\Entity\PendingRegistration;
use App\Entity\User;
use App\Enum\PowerLevel;
use App\Repository\BoardConfigRepository;
use App\Repository\ColorSchemeRepository;
use App\Repository\PendingRegistrationRepository;
use App\Repository\RankSetRepository;
use App\Repository\ThreadLayoutRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Two-step registration: request a code by email, then redeem it with a password.
 *
 * Differences from register.php that matter:
 *   - the code is 32 bytes from the CSPRNG, not eight characters from a rand()
 *     seeded with values the registrant controls
 *   - only the code's hash is stored, so reading the database does not let you
 *     verify someone else's pending account
 *   - the password is chosen by the user at redemption and hashed with the
 *     configured hasher, rather than the code itself being stored as the password
 *   - "is this name taken" is a unique index rather than a loop over every row in
 *     the users table
 */
final class RegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly PendingRegistrationRepository $pending,
        private readonly BoardConfigRepository $config,
        private readonly ColorSchemeRepository $schemes,
        private readonly ThreadLayoutRepository $layouts,
        private readonly RankSetRepository $rankSets,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(BOARD_NAME)%')]
        private readonly string $boardName,
    ) {
    }

    /**
     * Whether the viewer may register at all, per the board's policy.
     */
    public function registrationOpenFor(?User $viewer): bool
    {
        $policy = $this->config->get()->getRegistrationPolicy();

        if (null !== $viewer && $viewer->isBanned()) {
            return false;
        }

        return match ($policy) {
            BoardConfig::REGISTRATION_EVERYONE => true,
            BoardConfig::REGISTRATION_MEMBERS => null !== $viewer,
            BoardConfig::REGISTRATION_STAFF => null !== $viewer && $viewer->isStaff(),
            default => false,
        };
    }

    public function registrationClosedReason(?User $viewer): string
    {
        if (null !== $viewer && $viewer->isBanned()) {
            return 'Registration is not open to banned members.';
        }

        return match ($this->config->get()->getRegistrationPolicy()) {
            BoardConfig::REGISTRATION_MEMBERS => 'Registration is only open to members.',
            BoardConfig::REGISTRATION_STAFF => 'Registration is only open to staff.',
            default => 'Registration is currently closed.',
        };
    }

    /**
     * Starts a registration and emails the code.
     *
     * @return string|null an error message, or null on success. The message is
     *                     deliberately identical whether the name or the email was
     *                     the problem, so the form cannot be used to enumerate
     *                     which addresses already have accounts.
     */
    public function requestVerification(string $username, string $email, ?string $ip): ?string
    {
        if ($this->users->usernameExists($username)) {
            return 'That username is already taken.';
        }

        $existing = $this->pending->findByUsername($username);
        if (null !== $existing && !$existing->isExpired()) {
            return $this->resendReminder($existing);
        }

        // Cap accounts per address without revealing whether this one is in use.
        if ($this->users->emailInUse($email) || $this->pending->countPendingForEmail($email) >= 2) {
            return 'If that address can be used, a verification code is on its way to it.';
        }

        $code = bin2hex(random_bytes(16));
        $registration = new PendingRegistration($username, $email, $code, $ip);

        $this->em->persist($registration);
        $this->em->flush();

        $this->sendCode($registration, $code);

        return null;
    }

    /**
     * Redeems a code and creates the account.
     *
     * @return array{0: User|null, 1: string|null} the user, or null and an error
     */
    public function verify(string $username, string $code, string $plainPassword): array
    {
        $registration = $this->pending->findByUsername($username);

        if (null === $registration || !$registration->matchesCode($code)) {
            // One message for both cases: a wrong code and an unknown name must be
            // indistinguishable, or the form becomes a username oracle.
            return [null, 'That username and verification code do not match.'];
        }

        if ($registration->isExpired()) {
            return [null, 'That verification code has expired. Please register again.'];
        }

        if ($this->users->usernameExists($registration->getUsername())) {
            return [null, 'That username was taken while your registration was pending.'];
        }

        $user = $this->createUser($registration->getUsername(), $registration->getEmail(), $plainPassword);

        $this->em->remove($registration);
        $this->em->flush();

        return [$user, null];
    }

    /**
     * Creates an account directly, bypassing verification. Used by the installer,
     * the admin panel and the fixtures.
     */
    public function createUser(string $username, ?string $email, string $plainPassword, ?PowerLevel $power = null): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

        // The very first account to exist owns the board, as in the original's
        // "if there are no users yet, make this one an admin".
        $user->setPowerLevel($power ?? (0 === $this->users->countAll() ? PowerLevel::Owner : PowerLevel::Member));

        $user->setColorScheme($this->schemes->findDefault());
        $user->setThreadLayout($this->layouts->findDefault());
        $user->setRankSet($this->rankSets->findAllOrdered()[0] ?? null);
        $user->setTimezone($this->config->get()->getDefaultTimezone());
        $user->setLastActivityAt(new \DateTimeImmutable());

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function resendReminder(PendingRegistration $registration): string
    {
        if (!$registration->canSendReminder()) {
            return 'A verification code has already been sent for that username. Please check your email.';
        }

        // The stored code cannot be recovered - only its hash is kept - so a
        // reminder issues a fresh code rather than re-sending the old one.
        $code = bin2hex(random_bytes(16));
        $replacement = new PendingRegistration(
            $registration->getUsername(),
            $registration->getEmail(),
            $code,
            $registration->getIp(),
        );
        $replacement->recordReminder();

        $this->em->remove($registration);
        $this->em->flush();
        $this->em->persist($replacement);
        $this->em->flush();

        $this->sendCode($replacement, $code);

        return 'A new verification code has been emailed to you. You will not be able to ask for another.';
    }

    private function sendCode(PendingRegistration $registration, string $code): void
    {
        $from = $this->config->get()->getRegistrationEmail() ?? 'noreply@localhost';

        $email = (new TemplatedEmail())
            ->from($from)
            ->to($registration->getEmail())
            ->subject(\sprintf('Verification code for "%s" on %s', $registration->getUsername(), $this->boardName))
            ->htmlTemplate('email/verification.html.twig')
            ->textTemplate('email/verification.txt.twig')
            ->context([
                'boardName' => $this->boardName,
                'username' => $registration->getUsername(),
                'code' => $code,
                'expiresAt' => $registration->getExpiresAt(),
                'verifyUrl' => $this->urls->generate('app_register_verify', [
                    'username' => $registration->getUsername(),
                    'code' => $code,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);

        $this->mailer->send($email);
    }
}
