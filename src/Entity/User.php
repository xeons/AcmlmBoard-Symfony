<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PowerLevel;
use App\Enum\Sex;
use App\Enum\SignatureDisplay;
use App\Enum\SignatureSeparator;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'idx_user_posts', columns: ['posts'])]
#[ORM\Index(name: 'idx_user_last_activity', columns: ['last_activity_at'])]
#[ORM\Index(name: 'idx_user_last_post', columns: ['last_post_at'])]
#[ORM\Index(name: 'idx_user_current_forum', columns: ['current_forum_id'])]
#[UniqueEntity(fields: ['username'], message: 'That username is already taken.')]
#[UniqueEntity(fields: ['usernameCanonical'], errorPath: 'username', message: 'That username is too similar to an existing one.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    /** Authenticator apps assume 6 digits over 30 seconds; anything else confuses them. */
    public const TOTP_DIGITS = 6;
    public const TOTP_PERIOD = 30;
    public const TOTP_ALGORITHM = 'sha1';

    /**
     * A path to an image served by this board, as the avatar gallery produces.
     *
     * Directory segments carry no dots, so "/images/../../secret.png" cannot match,
     * and an image extension is required so the value can never be a script or a
     * javascript: URL smuggled into an <img src>.
     */
    public const LOCAL_IMAGE_PATTERN = '~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|gif|webp)$~';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 25, unique: true)]
    #[Assert\NotBlank(message: 'Please enter a username.')]
    #[Assert\Length(min: 2, max: 25)]
    #[Assert\Regex(
        pattern: '/^[^\x00-\x1F\x7F<>]+$/',
        message: 'Usernames may not contain control characters or angle brackets.',
    )]
    private string $username = '';

    /**
     * register.php compared names with spaces and &nbsp; stripped, case-insensitively,
     * to stop "Ac mlm" from impersonating "Acmlm". That comparison was done by looping
     * over every row in `users` on each registration; here it is a unique index.
     */
    #[ORM\Column(length: 25, unique: true)]
    private string $usernameCanonical = '';

    #[ORM\Column]
    private string $password = '';

    /**
     * True for accounts imported from a legacy board whose `password` column still
     * holds a raw md5. Cleared automatically on first successful login.
     *
     * @see \App\Security\LegacyMd5PasswordHasher
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $passwordLegacyMd5 = false;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'That does not look like an email address.')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** false -> staff only, true -> any logged-in user. Never shown to guests. */
    #[ORM\Column(options: ['default' => true])]
    private bool $emailPublic = true;

    #[ORM\Column(enumType: PowerLevel::class, options: ['default' => 0])]
    private PowerLevel $powerLevel = PowerLevel::Member;

    #[ORM\Column(enumType: Sex::class, options: ['default' => 2])]
    private Sex $sex = Sex::Undisclosed;

    /**
     * Denormalised post count. The original incremented this in newthread/newreply
     * and never repaired it; PostManager keeps it in step and a console command
     * (app:board:recount) rebuilds it.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $posts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPostAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastUrl = null;

    /** Which forum the user is currently browsing, for the per-forum "who's here" line. */
    #[ORM\ManyToOne(targetEntity: Forum::class)]
    #[ORM\JoinColumn(name: 'current_forum_id', nullable: true, onDelete: 'SET NULL')]
    private ?Forum $currentForum = null;

    // ------------------------------------------------------------------
    // Appearance
    // ------------------------------------------------------------------

    /** Custom title shown beneath the rank. Sanitised with app.title_sanitizer. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /**
     * 0 -> may never set a title, 1 -> may set one once the post threshold is met,
     * 2 -> always allowed (granted by staff). Mirrors `users.titleoption`.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $titleOption = 1;

    #[ORM\ManyToOne(targetEntity: RankSet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RankSet $rankSet = null;

    /** A picture elsewhere on the web, or one from this board's own gallery. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\AtLeastOneOf(
        constraints: [
            new Assert\Url(requireTld: true, protocols: ['http', 'https']),
            new Assert\Regex(pattern: self::LOCAL_IMAGE_PATTERN),
        ],
        message: 'The user picture must be a full http(s) URL, or an image on this board such as /images/avatars/kirby.png.',
        includeInternalMessages: false,
    )]
    #[Assert\Length(max: 255)]
    private ?string $picture = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\AtLeastOneOf(
        constraints: [
            new Assert\Url(requireTld: true, protocols: ['http', 'https']),
            new Assert\Regex(pattern: self::LOCAL_IMAGE_PATTERN),
        ],
        message: 'The minipic must be a full http(s) URL, or an image on this board.',
        includeInternalMessages: false,
    )]
    #[Assert\Length(max: 255)]
    private ?string $miniPic = null;

    /** CSS background shorthand applied behind the post header. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $postBackground = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 65535)]
    private ?string $postHeader = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 65535)]
    private ?string $signature = null;

    // ------------------------------------------------------------------
    // Personal information
    // ------------------------------------------------------------------

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 65535)]
    private ?string $bio = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $realName = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $location = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\LessThanOrEqual('today', message: 'Your birthday cannot be in the future.')]
    private ?\DateTimeImmutable $birthday = null;

    /**
     * Month and day packed as MMDD, kept in step by setBirthday().
     *
     * The index page needs "whose birthday is today", which the original expressed as
     * `FROM_UNIXTIME(birthday,'%m-%d') = ...` - a function call on every row, so the
     * index on birthday could never be used. This column is directly indexable and
     * needs no vendor-specific date functions in DQL.
     */
    #[ORM\Column(nullable: true)]
    private ?int $birthdayMonthDay = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(message: 'The homepage must be a full http(s) URL.', requireTld: true)]
    #[Assert\Length(max: 255)]
    private ?string $homepageUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $homepageName = null;

    // ------------------------------------------------------------------
    // Preferences
    // ------------------------------------------------------------------

    #[ORM\Column(options: ['default' => 20])]
    #[Assert\Range(min: 5, max: 100, notInRangeMessage: 'Posts per page must be between {{ min }} and {{ max }}.')]
    private int $postsPerPage = 20;

    #[ORM\Column(options: ['default' => 50])]
    #[Assert\Range(min: 10, max: 200, notInRangeMessage: 'Threads per page must be between {{ min }} and {{ max }}.')]
    private int $threadsPerPage = 50;

    /**
     * IANA timezone identifier, e.g. "America/New_York".
     *
     * The original stored a float number of hours in `users.timezone` and asked the
     * member to work out their own offset - which is a question with no stable
     * answer, because it changes twice a year under daylight saving. A member in
     * London who set +0 in January was an hour wrong from March to October, every
     * year, and had to notice and fix it themselves.
     *
     * A named zone carries its own DST rules, so the arithmetic is PHP's problem
     * rather than the member's.
     */
    #[ORM\Column(length: 64, options: ['default' => 'UTC'])]
    #[Assert\Timezone(message: 'Please choose a valid timezone.')]
    private string $timezone = 'UTC';

    #[ORM\ManyToOne(targetEntity: ColorScheme::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ColorScheme $colorScheme = null;

    #[ORM\ManyToOne(targetEntity: ThreadLayout::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ThreadLayout $threadLayout = null;

    #[ORM\Column(enumType: SignatureDisplay::class, options: ['default' => 1])]
    private SignatureDisplay $signatureDisplay = SignatureDisplay::AsPosted;

    #[ORM\Column(enumType: SignatureSeparator::class, options: ['default' => 0])]
    private SignatureSeparator $signatureSeparator = SignatureSeparator::Dashes;

    #[ORM\Column(options: ['default' => true])]
    private bool $postToolbar = true;

    /** `users.oldstylemark`: put "Mark forum read" outside the nav menu. */
    #[ORM\Column(options: ['default' => false])]
    private bool $markReadOutsideMenu = false;

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /** @var Collection<int, Favorite> */
    #[ORM\OneToMany(targetEntity: Favorite::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $favorites;

    /** @var Collection<int, ForumModerator> */
    #[ORM\OneToMany(targetEntity: ForumModerator::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $moderatedForums;

    /** Users whose post layouts this user has chosen not to render. @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'blocked_layouts')]
    #[ORM\JoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'blocked_user_id', onDelete: 'CASCADE')]
    private Collection $blockedLayouts;

    /** Post-count rivals shown in the header ticker. @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'post_radar')]
    #[ORM\JoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'rival_id', onDelete: 'CASCADE')]
    private Collection $postRadar;

    #[ORM\OneToOne(targetEntity: RpgProfile::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?RpgProfile $rpgProfile = null;

    /**
     * Opaque, stable identifier handed to authenticators as the WebAuthn user handle.
     *
     * Deliberately not the primary key. The handle is stored on the member's own
     * device and comes back on every passkey sign-in, so using a sequential id would
     * leak how many accounts the board has and let one member infer another's id.
     * Generated on first use; see PasskeyService.
     */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $webauthnHandle = null;

    public function getWebauthnHandle(): ?string
    {
        return $this->webauthnHandle;
    }

    public function setWebauthnHandle(?string $handle): static
    {
        $this->webauthnHandle = $handle;

        return $this;
    }

    // ------------------------------------------------------------------
    // Authenticator app (TOTP)
    // ------------------------------------------------------------------

    /**
     * The shared secret, encrypted. Set through TotpService, never directly: the
     * value on the way in is ciphertext from SecretCipher, and a plain base32 seed
     * written here would be stored in the clear.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpSecret = null;

    /** Null until a code has actually been verified, so a half-finished setup is off. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $totpConfirmedAt = null;

    /**
     * Recovery codes, hashed. They are password-equivalent - each one bypasses the
     * second factor exactly once - so they are stored the same way a password is.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $totpRecoveryCodes = [];

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $encrypted): static
    {
        $this->totpSecret = $encrypted;

        return $this;
    }

    public function getTotpConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->totpConfirmedAt;
    }

    public function setTotpConfirmedAt(?\DateTimeImmutable $at): static
    {
        $this->totpConfirmedAt = $at;

        return $this;
    }

    /** @return list<string> */
    public function getTotpRecoveryCodes(): array
    {
        return $this->totpRecoveryCodes;
    }

    /** @param list<string> $hashes */
    public function setTotpRecoveryCodes(array $hashes): static
    {
        $this->totpRecoveryCodes = array_values($hashes);

        return $this;
    }

    public function countUnusedRecoveryCodes(): int
    {
        return \count($this->totpRecoveryCodes);
    }

    // -------------------------------------------------- scheb integration

    /**
     * Requires a secret that can actually be *used*, not merely one that is stored.
     *
     * The seed is encrypted under a key derived from APP_SECRET. Rotate that and
     * every stored seed becomes undecryptable - and reporting "enabled" while
     * getTotpAuthenticationConfiguration() returns null makes the two-factor
     * provider throw during sign-in, locking the member out completely: the
     * exception fires before the challenge renders, so not even a recovery code
     * can be entered. Falling back to password-only is a downgrade, but it is a
     * recoverable one, and the authenticator page then honestly reports "off".
     */
    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->decryptedTotpSecret && null !== $this->totpConfirmedAt;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->username;
    }

    /**
     * Built by TotpService, which holds the decryption key. The entity has no way to
     * decrypt its own secret, which keeps the key out of anything that merely loads
     * a user.
     */
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->decryptedTotpSecret) {
            return null;
        }

        return new TotpConfiguration(
            $this->decryptedTotpSecret,
            self::TOTP_ALGORITHM,
            self::TOTP_PERIOD,
            self::TOTP_DIGITS,
        );
    }

    /** Populated for the duration of a request by TotpService. Never persisted. */
    private ?string $decryptedTotpSecret = null;

    public function withDecryptedTotpSecret(?string $secret): static
    {
        $this->decryptedTotpSecret = $secret;

        return $this;
    }

    /**
     * Recovery codes are compared in constant time and only against unused ones.
     */
    public function isBackupCode(string $code): bool
    {
        $candidate = self::hashRecoveryCode($code);

        foreach ($this->totpRecoveryCodes as $stored) {
            if (hash_equals($stored, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $candidate = self::hashRecoveryCode($code);

        $this->totpRecoveryCodes = array_values(array_filter(
            $this->totpRecoveryCodes,
            static fn (string $stored): bool => !hash_equals($stored, $candidate),
        ));
    }

    /**
     * Recovery codes carry ~50 bits of entropy and are used at most once, so a fast
     * hash is appropriate here in a way it would not be for a password.
     */
    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper(str_replace([' ', '-'], '', $code)));
    }

    public function __construct()
    {
        $this->registeredAt = new \DateTimeImmutable();
        $this->favorites = new ArrayCollection();
        $this->moderatedForums = new ArrayCollection();
        $this->blockedLayouts = new ArrayCollection();
        $this->postRadar = new ArrayCollection();
    }

    // ------------------------------------------------------------------
    // Security contract
    // ------------------------------------------------------------------

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique($this->powerLevel->roles()));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isPasswordLegacyMd5(): bool
    {
        return $this->passwordLegacyMd5;
    }

    public function setPasswordLegacyMd5(bool $legacy): static
    {
        $this->passwordLegacyMd5 = $legacy;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // Nothing transient is held on the entity.
    }

    // ------------------------------------------------------------------
    // Derived state
    // ------------------------------------------------------------------

    public function isBanned(): bool
    {
        return PowerLevel::Banned === $this->powerLevel;
    }

    public function isStaff(): bool
    {
        return $this->powerLevel->atLeast(PowerLevel::LocalModerator);
    }

    public function isModerator(): bool
    {
        return $this->powerLevel->atLeast(PowerLevel::Moderator);
    }

    public function isAdmin(): bool
    {
        return $this->powerLevel->atLeast(PowerLevel::Administrator);
    }

    /**
     * Effective power for permission checks. Matches lib/function.php, which zeroed
     * the level of banned users so that a ban could not grant negative-threshold access.
     */
    public function effectivePower(): int
    {
        return max(0, $this->powerLevel->value);
    }

    /** Whole days since registration; the RPG maths is defined over this. */
    public function daysRegistered(?\DateTimeImmutable $now = null): float
    {
        $now ??= new \DateTimeImmutable();

        return max(0.0, ($now->getTimestamp() - $this->registeredAt->getTimestamp()) / 86400);
    }

    /**
     * Converts an instant to this member's local wall-clock time.
     *
     * Because the zone is named rather than a fixed offset, this is correct across
     * daylight-saving transitions without anyone having to adjust anything.
     */
    public function toLocalTime(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone($this->getTimezoneObject());
    }

    public function getTimezoneObject(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone);
        } catch (\Exception) {
            // A zone can be retired between PHP releases (tzdata drops identifiers).
            // Falling back beats throwing on every page the member loads.
            return new \DateTimeZone('UTC');
        }
    }

    /** Current offset from UTC in minutes, for display only. */
    public function getCurrentUtcOffsetMinutes(?\DateTimeImmutable $at = null): int
    {
        $at ??= new \DateTimeImmutable();

        return intdiv($this->getTimezoneObject()->getOffset($at), 60);
    }

    public function isOnlineAt(\DateTimeImmutable $threshold): bool
    {
        return null !== $this->lastActivityAt && $this->lastActivityAt > $threshold;
    }

    public function hasBlockedLayoutOf(self $other): bool
    {
        return $this->blockedLayouts->contains($other);
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        $this->usernameCanonical = self::canonicalizeUsername($username);

        return $this;
    }

    public function getUsernameCanonical(): string
    {
        return $this->usernameCanonical;
    }

    /**
     * Collapses the variations register.php tried to catch: case, ASCII spaces,
     * non-breaking spaces (both the literal U+00A0 and a typed "&nbsp;"), and
     * Unicode look-alike whitespace.
     */
    public static function canonicalizeUsername(string $name): string
    {
        $name = str_ireplace('&nbsp;', '', $name);
        $name = preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', '', $name) ?? $name;

        return mb_strtolower($name);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function isEmailPublic(): bool
    {
        return $this->emailPublic;
    }

    public function setEmailPublic(bool $emailPublic): static
    {
        $this->emailPublic = $emailPublic;

        return $this;
    }

    public function getPowerLevel(): PowerLevel
    {
        return $this->powerLevel;
    }

    public function setPowerLevel(PowerLevel $powerLevel): static
    {
        $this->powerLevel = $powerLevel;

        return $this;
    }

    public function getSex(): Sex
    {
        return $this->sex;
    }

    /**
     * Null is accepted and means Undisclosed.
     *
     * The form renders this as radio buttons, and a browser sends nothing at all for
     * a radio group with no selection - which happens to anyone whose stored value is
     * not among the selectable ones. A non-nullable setter turned that into a 500
     * from deep inside the property accessor rather than a form the member could fix.
     */
    public function setSex(?Sex $sex): static
    {
        $this->sex = $sex ?? Sex::Undisclosed;

        return $this;
    }

    public function getPosts(): int
    {
        return $this->posts;
    }

    public function setPosts(int $posts): static
    {
        $this->posts = $posts;

        return $this;
    }

    public function incrementPosts(int $by = 1): static
    {
        $this->posts += $by;

        return $this;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(\DateTimeImmutable $registeredAt): static
    {
        $this->registeredAt = $registeredAt;

        return $this;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?\DateTimeImmutable $at): static
    {
        $this->lastActivityAt = $at;

        return $this;
    }

    public function getLastPostAt(): ?\DateTimeImmutable
    {
        return $this->lastPostAt;
    }

    public function setLastPostAt(?\DateTimeImmutable $at): static
    {
        $this->lastPostAt = $at;

        return $this;
    }

    public function getLastIp(): ?string
    {
        return $this->lastIp;
    }

    public function setLastIp(?string $ip): static
    {
        $this->lastIp = $ip;

        return $this;
    }

    public function getLastUrl(): ?string
    {
        return $this->lastUrl;
    }

    public function setLastUrl(?string $url): static
    {
        $this->lastUrl = null === $url ? null : mb_substr($url, 0, 255);

        return $this;
    }

    public function getCurrentForum(): ?Forum
    {
        return $this->currentForum;
    }

    public function setCurrentForum(?Forum $forum): static
    {
        $this->currentForum = $forum;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitleOption(): int
    {
        return $this->titleOption;
    }

    public function setTitleOption(int $titleOption): static
    {
        $this->titleOption = $titleOption;

        return $this;
    }

    public function getRankSet(): ?RankSet
    {
        return $this->rankSet;
    }

    public function setRankSet(?RankSet $rankSet): static
    {
        $this->rankSet = $rankSet;

        return $this;
    }

    public function getPicture(): ?string
    {
        return $this->picture;
    }

    public function setPicture(?string $picture): static
    {
        $this->picture = $picture;

        return $this;
    }

    public function getMiniPic(): ?string
    {
        return $this->miniPic;
    }

    public function setMiniPic(?string $miniPic): static
    {
        $this->miniPic = $miniPic;

        return $this;
    }

    public function getPostBackground(): ?string
    {
        return $this->postBackground;
    }

    public function setPostBackground(?string $postBackground): static
    {
        $this->postBackground = $postBackground;

        return $this;
    }

    public function getPostHeader(): ?string
    {
        return $this->postHeader;
    }

    public function setPostHeader(?string $postHeader): static
    {
        $this->postHeader = $postHeader;

        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): static
    {
        $this->signature = $signature;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getRealName(): ?string
    {
        return $this->realName;
    }

    public function setRealName(?string $realName): static
    {
        $this->realName = $realName;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getBirthday(): ?\DateTimeImmutable
    {
        return $this->birthday;
    }

    public function setBirthday(?\DateTimeImmutable $birthday): static
    {
        $this->birthday = $birthday;
        $this->birthdayMonthDay = null === $birthday
            ? null
            : (int) $birthday->format('n') * 100 + (int) $birthday->format('j');

        return $this;
    }

    public function getBirthdayMonthDay(): ?int
    {
        return $this->birthdayMonthDay;
    }

    /** Age in years on the given date, or null when no birthday is set. */
    public function getAgeOn(\DateTimeImmutable $on): ?int
    {
        return null === $this->birthday ? null : $this->birthday->diff($on)->y;
    }

    public function getHomepageUrl(): ?string
    {
        return $this->homepageUrl;
    }

    public function setHomepageUrl(?string $homepageUrl): static
    {
        $this->homepageUrl = $homepageUrl;

        return $this;
    }

    public function getHomepageName(): ?string
    {
        return $this->homepageName;
    }

    public function setHomepageName(?string $homepageName): static
    {
        $this->homepageName = $homepageName;

        return $this;
    }

    public function getPostsPerPage(): int
    {
        return $this->postsPerPage;
    }

    public function setPostsPerPage(int $postsPerPage): static
    {
        $this->postsPerPage = $postsPerPage;

        return $this;
    }

    public function getThreadsPerPage(): int
    {
        return $this->threadsPerPage;
    }

    public function setThreadsPerPage(int $threadsPerPage): static
    {
        $this->threadsPerPage = $threadsPerPage;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getColorScheme(): ?ColorScheme
    {
        return $this->colorScheme;
    }

    public function setColorScheme(?ColorScheme $colorScheme): static
    {
        $this->colorScheme = $colorScheme;

        return $this;
    }

    public function getThreadLayout(): ?ThreadLayout
    {
        return $this->threadLayout;
    }

    public function setThreadLayout(?ThreadLayout $threadLayout): static
    {
        $this->threadLayout = $threadLayout;

        return $this;
    }

    public function getSignatureDisplay(): SignatureDisplay
    {
        return $this->signatureDisplay;
    }

    public function setSignatureDisplay(SignatureDisplay $display): static
    {
        $this->signatureDisplay = $display;

        return $this;
    }

    public function getSignatureSeparator(): SignatureSeparator
    {
        return $this->signatureSeparator;
    }

    public function setSignatureSeparator(SignatureSeparator $separator): static
    {
        $this->signatureSeparator = $separator;

        return $this;
    }

    public function hasPostToolbar(): bool
    {
        return $this->postToolbar;
    }

    public function setPostToolbar(bool $postToolbar): static
    {
        $this->postToolbar = $postToolbar;

        return $this;
    }

    public function isMarkReadOutsideMenu(): bool
    {
        return $this->markReadOutsideMenu;
    }

    public function setMarkReadOutsideMenu(bool $outside): static
    {
        $this->markReadOutsideMenu = $outside;

        return $this;
    }

    /** @return Collection<int, Favorite> */
    public function getFavorites(): Collection
    {
        return $this->favorites;
    }

    /** @return Collection<int, ForumModerator> */
    public function getModeratedForums(): Collection
    {
        return $this->moderatedForums;
    }

    /** @return Collection<int, self> */
    public function getBlockedLayouts(): Collection
    {
        return $this->blockedLayouts;
    }

    public function blockLayoutOf(self $other): static
    {
        if (!$this->blockedLayouts->contains($other)) {
            $this->blockedLayouts->add($other);
        }

        return $this;
    }

    public function unblockLayoutOf(self $other): static
    {
        $this->blockedLayouts->removeElement($other);

        return $this;
    }

    /** @return Collection<int, self> */
    public function getPostRadar(): Collection
    {
        return $this->postRadar;
    }

    public function addRival(self $rival): static
    {
        if ($rival !== $this && !$this->postRadar->contains($rival)) {
            $this->postRadar->add($rival);
        }

        return $this;
    }

    public function removeRival(self $rival): static
    {
        $this->postRadar->removeElement($rival);

        return $this;
    }

    public function getRpgProfile(): ?RpgProfile
    {
        return $this->rpgProfile;
    }

    public function setRpgProfile(?RpgProfile $profile): static
    {
        $this->rpgProfile = $profile;

        return $this;
    }

    public function __toString(): string
    {
        return $this->username;
    }
}
