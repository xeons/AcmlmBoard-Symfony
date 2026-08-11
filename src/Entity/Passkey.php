<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PasskeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A registered WebAuthn credential - a passkey.
 *
 * The credential itself is stored as the library's own serialised
 * PublicKeyCredentialSource, because its shape is the library's business and will
 * change with the spec. The columns beside it exist only so the database can answer
 * the two questions the login flow asks - "which credential is this id?" and "which
 * passkeys does this member have?" - without deserialising every row.
 *
 * Nothing here is secret in the way a password hash is: a passkey's *private* key
 * never leaves the authenticator. What is stored is the public key, so a database
 * disclosure does not let anyone sign in.
 */
#[ORM\Entity(repositoryClass: PasskeyRepository::class)]
#[ORM\Table(name: 'passkeys')]
#[ORM\UniqueConstraint(name: 'uniq_passkey_credential', columns: ['credential_id'])]
#[ORM\Index(name: 'idx_passkey_user', columns: ['user_id'])]
class Passkey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * The raw credential id, base64url-encoded so it can live in a unique index.
     * Credential ids can be long, so this is generously sized.
     */
    #[ORM\Column(length: 512)]
    private string $credentialId;

    /** The library's serialised PublicKeyCredentialSource. */
    #[ORM\Column(type: Types::TEXT)]
    private string $credentialSource;

    /**
     * A name the member gives it, so a list of three passkeys is intelligible.
     * Defaulted from the authenticator when one can be inferred.
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Give this passkey a name so you can recognise it later.')]
    #[Assert\Length(max: 100)]
    private string $name = 'Passkey';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * The authenticator's signature counter.
     *
     * A counter that goes backwards is the signal that a credential has been cloned;
     * the library's counter checker enforces that, and this column is what it
     * compares against.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $signCount = 0;

    /** Authenticator model identifier, where the authenticator reports one. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $aaguid = null;

    /**
     * Whether the credential is synced to a provider's cloud (iCloud Keychain,
     * Google Password Manager). Worth surfacing: a synced passkey survives losing
     * the device, a device-bound one does not.
     */
    #[ORM\Column(nullable: true)]
    private ?bool $backedUp = null;

    public function __construct(User $user, string $credentialId, string $credentialSource, string $name)
    {
        $this->user = $user;
        $this->credentialId = $credentialId;
        $this->credentialSource = $credentialSource;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function getCredentialSource(): string
    {
        return $this->credentialSource;
    }

    public function setCredentialSource(string $source): static
    {
        $this->credentialSource = $source;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function recordUse(int $signCount, ?\DateTimeImmutable $at = null): static
    {
        $this->lastUsedAt = $at ?? new \DateTimeImmutable();
        $this->signCount = $signCount;

        return $this;
    }

    public function getSignCount(): int
    {
        return $this->signCount;
    }

    public function getAaguid(): ?string
    {
        return $this->aaguid;
    }

    public function setAaguid(?string $aaguid): static
    {
        $this->aaguid = $aaguid;

        return $this;
    }

    public function isBackedUp(): ?bool
    {
        return $this->backedUp;
    }

    public function setBackedUp(?bool $backedUp): static
    {
        $this->backedUp = $backedUp;

        return $this;
    }
}
