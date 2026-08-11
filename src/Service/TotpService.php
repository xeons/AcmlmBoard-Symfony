<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\BoardConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use OTPHP\TOTP;

/**
 * Setting up, confirming and removing an authenticator app.
 *
 * Codes themselves are checked by scheb's TOTP provider during login; this covers
 * everything around that - generating a seed, proving the member's app is in step
 * before switching the requirement on, issuing recovery codes, and turning it off.
 */
final class TotpService
{
    /** How many recovery codes to issue, and how long each one is. */
    private const RECOVERY_CODES = 10;
    private const RECOVERY_BYTES = 5;

    /**
     * 160 bits, as RFC 4226 recommends. The library's default is far longer, which
     * is valid but produces a 100-character key that nobody can type in by hand.
     */
    private const SECRET_BYTES = 20;

    /**
     * Tolerated clock drift when confirming or removing an authenticator. otphp
     * requires this to be under one period.
     */
    private const LEEWAY_SECONDS = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SecretCipher $cipher,
        private readonly BoardConfigRepository $config,
    ) {
    }

    public function isEnabledOnBoard(): bool
    {
        return $this->config->get()->isTotpEnabled();
    }

    /**
     * A fresh seed, stored but not yet in force.
     *
     * Nothing is required until confirm() succeeds, so a member who walks away
     * halfway through is left exactly as they were rather than locked out.
     */
    public function beginSetup(User $user): string
    {
        $secret = trim(TOTP::generate(secretSize: self::SECRET_BYTES)->getSecret());

        $user->setTotpSecret($this->cipher->encrypt($secret));
        $user->withDecryptedTotpSecret($secret);
        $user->setTotpConfirmedAt(null);
        $this->em->flush();

        return $secret;
    }

    /**
     * Switches the requirement on, but only if the app is genuinely in step.
     *
     * @return list<string>|null the recovery codes, or null if the code was wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        $secret = $this->secretFor($user);
        if (null === $secret || !$this->verify($secret, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->setTotpConfirmedAt(new \DateTimeImmutable());
        $user->setTotpRecoveryCodes(array_map(User::hashRecoveryCode(...), $codes));
        $this->em->flush();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->setTotpSecret(null);
        $user->withDecryptedTotpSecret(null);
        $user->setTotpConfirmedAt(null);
        $user->setTotpRecoveryCodes([]);
        $this->em->flush();
    }

    /** @return list<string> the new codes, in plain text, shown once */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->setTotpRecoveryCodes(array_map(User::hashRecoveryCode(...), $codes));
        $this->em->flush();

        return $codes;
    }

    /** The decrypted seed, or null if there is none or it will not decrypt. */
    public function secretFor(User $user): ?string
    {
        $stored = $user->getTotpSecret();

        return null === $stored ? null : $this->cipher->decrypt($stored);
    }

    /**
     * Hands the entity its decrypted seed so scheb can build a configuration from
     * it. Called by the listener that runs before authentication.
     */
    public function attachSecret(User $user): void
    {
        $user->withDecryptedTotpSecret($this->secretFor($user));
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('~\s+~', '', $code) ?? '';
        if (!preg_match('~^\d{'.User::TOTP_DIGITS.'}$~', $code)) {
            return false;
        }

        // One period either side, which covers a phone whose clock has drifted a
        // little without meaningfully widening the window for guessing.
        return $this->totp($secret)->verify($code, null, self::LEEWAY_SECONDS);
    }

    /** The otpauth:// URI an authenticator app scans or accepts by hand. */
    public function provisioningUri(User $user, string $secret): string
    {
        $totp = $this->totp($secret);
        $totp->setLabel($user->getUsername());
        $totp->setIssuer($this->config->get()->getBoardName());

        return $totp->getProvisioningUri();
    }

    /** The same URI as a PNG, so it can be scanned rather than typed. */
    public function qrCode(User $user, string $secret): string
    {
        return (new Builder(
            writer: new PngWriter(),
            data: $this->provisioningUri($user, $secret),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 8,
        ))->build()->getString();
    }

    /** The seed in the spaced blocks every authenticator app expects for typing. */
    public function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private function totp(string $secret): TOTP
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(User::TOTP_PERIOD);
        $totp->setDigits(User::TOTP_DIGITS);
        $totp->setDigest(User::TOTP_ALGORITHM);

        return $totp;
    }

    /**
     * Codes are shown as XXXXX-XXXXX in Crockford-ish base32 - no vowels, so no
     * accidental words, and no characters that are easy to confuse when copying
     * one off a piece of paper.
     *
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $alphabet = '23456789BCDFGHJKLMNPQRSTVWXZ';
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; ++$i) {
            $code = '';
            for ($n = 0; $n < self::RECOVERY_BYTES * 2; ++$n) {
                $code .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
            }
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
        }

        return $codes;
    }
}
