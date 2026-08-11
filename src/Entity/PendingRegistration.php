<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PendingRegistrationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An account awaiting email verification (the original `limbousers` table).
 *
 * The original generated the verification code by seeding PHP's `rand()` with
 * `time() / (strlen(email) + strlen(username) + sum-of-ip-octets)` and drawing eight
 * characters from a 32-symbol alphabet. Every input to that seed is known to the
 * person registering, so codes were predictable - and the code was then stored in
 * cleartext and reused as the account's initial password field. Here the code comes
 * from random_bytes() and only its hash is stored, so a database read cannot verify
 * anyone else's account.
 */
#[ORM\Entity(repositoryClass: PendingRegistrationRepository::class)]
#[ORM\Table(name: 'pending_registrations')]
#[ORM\UniqueConstraint(name: 'uniq_pending_username', columns: ['username_canonical'])]
#[ORM\Index(name: 'idx_pending_email', columns: ['email'])]
#[ORM\Index(name: 'idx_pending_expiry', columns: ['expires_at'])]
class PendingRegistration
{
    /** Codes are single-use and short-lived. */
    public const TTL = '+7 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 25)]
    private string $username;

    #[ORM\Column(length: 25)]
    private string $usernameCanonical;

    #[ORM\Column(length: 180)]
    private string $email;

    /** sha-256 of the emailed code. The code itself is never persisted. */
    #[ORM\Column(length: 64)]
    private string $codeHash;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** One reminder resend is allowed, matching the original's `reminded` counter. */
    #[ORM\Column(options: ['default' => 0])]
    private int $remindersSent = 0;

    public function __construct(string $username, string $email, string $code, ?string $ip = null)
    {
        $this->username = $username;
        $this->usernameCanonical = User::canonicalizeUsername($username);
        $this->email = $email;
        $this->codeHash = self::hashCode($code);
        $this->ip = $ip;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify(self::TTL);
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /** Constant-time comparison; a timing oracle here would leak the code. */
    public function matchesCode(string $code): bool
    {
        return hash_equals($this->codeHash, self::hashCode($code));
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function canSendReminder(): bool
    {
        return $this->remindersSent < 1;
    }

    public function recordReminder(): static
    {
        ++$this->remindersSent;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUsernameCanonical(): string
    {
        return $this->usernameCanonical;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRemindersSent(): int
    {
        return $this->remindersSent;
    }
}
