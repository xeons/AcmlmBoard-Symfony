<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoardConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Single-row board settings table, replacing `boardconfig` plus the separate
 * `boardconfiginfo` table that held each key's label and help text. The labels live
 * with the form type now, so a setting cannot exist without a description.
 */
#[ORM\Entity(repositoryClass: BoardConfigRepository::class)]
#[ORM\Table(name: 'board_config')]
class BoardConfig
{
    public const SINGLETON_ID = 1;

    /** Who may create an account. */
    public const REGISTRATION_EVERYONE = 0;
    public const REGISTRATION_MEMBERS = 1;
    public const REGISTRATION_STAFF = 2;
    public const REGISTRATION_CLOSED = 3;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(length: 100, options: ['default' => "Acmlm's Board"])]
    #[Assert\NotBlank]
    private string $boardName = "Acmlm's Board";

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true)]
    private ?string $boardUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $siteName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true)]
    private ?string $siteUrl = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\Choice(choices: [
        self::REGISTRATION_EVERYONE,
        self::REGISTRATION_MEMBERS,
        self::REGISTRATION_STAFF,
        self::REGISTRATION_CLOSED,
    ])]
    private int $registrationPolicy = self::REGISTRATION_EVERYONE;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $registrationEmail = null;

    /** Whether admins may lock threads against moderator edits. */
    #[ORM\Column(options: ['default' => false])]
    private bool $threadLockingEnabled = false;

    /** Minimum power level required to use search. */
    #[ORM\Column(options: ['default' => 0])]
    private int $searchMinPower = 0;

    /** Minimum power level required to issue forum bans. */
    #[ORM\Column(options: ['default' => 2])]
    private int $forumBanMinPower = 2;

    /**
     * Account that owns the posts of deleted users, so their threads survive.
     * The original force-banned this account on every request; it is simply excluded
     * from listings here.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deletedUserAccount = null;

    /** Account that sends system messages. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $systemAccount = null;

    /** Staff-only forum holding per-user disciplinary threads. */
    #[ORM\ManyToOne(targetEntity: Forum::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Forum $disciplinaryForum = null;

    /** Forum that receives threads sent to the trash. */
    #[ORM\ManyToOne(targetEntity: Forum::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Forum $trashForum = null;

    /**
     * Posts a user may make in one thread per day. The original hardcoded 50, or 5 if
     * the thread title contained "ACS ".
     */
    #[ORM\Column(options: ['default' => 50])]
    #[Assert\Positive]
    private int $maxPostsPerThreadPerDay = 50;

    /** Post count at which a user may set a custom title. */
    #[ORM\Column(options: ['default' => 2000])]
    private int $customTitlePostThreshold = 2000;

    /** Alternative route to a custom title: this many posts plus this many days. */
    #[ORM\Column(options: ['default' => 1000])]
    private int $customTitleAgePostThreshold = 1000;

    #[ORM\Column(options: ['default' => 200])]
    private int $customTitleAgeDayThreshold = 200;

    /** Block a user from replying twice in a row (staff and above are exempt). */
    #[ORM\Column(options: ['default' => true])]
    private bool $preventDoublePosting = true;

    /** Require an emailed verification code before an account becomes usable. */
    #[ORM\Column(options: ['default' => true])]
    private bool $requireEmailVerification = true;

    /**
     * Timezone used for guests and as the default for new accounts.
     *
     * The original had no such notion: "board time" was whatever the server clock
     * said, shifted by a hardcoded `$servertimeoffset` in a config file, and every
     * member had to work out their own offset from that.
     */
    #[ORM\Column(length: 64, options: ['default' => 'UTC'])]
    #[Assert\Timezone]
    private string $defaultTimezone = 'UTC';

    /** Allow members to register passkeys and sign in without a password. */
    #[ORM\Column(options: ['default' => true])]
    private bool $passkeysEnabled = true;

    /** Allow members to protect their account with an authenticator app. */
    #[ORM\Column(options: ['default' => true])]
    private bool $totpEnabled = true;

    public function getDefaultTimezone(): string
    {
        return $this->defaultTimezone;
    }

    public function setDefaultTimezone(string $timezone): static
    {
        $this->defaultTimezone = $timezone;

        return $this;
    }

    public function isPasskeysEnabled(): bool
    {
        return $this->passkeysEnabled;
    }

    public function setPasskeysEnabled(bool $enabled): static
    {
        $this->passkeysEnabled = $enabled;

        return $this;
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function setTotpEnabled(bool $enabled): static
    {
        $this->totpEnabled = $enabled;

        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBoardName(): string
    {
        return $this->boardName;
    }

    public function setBoardName(string $boardName): static
    {
        $this->boardName = $boardName;

        return $this;
    }

    public function getBoardUrl(): ?string
    {
        return $this->boardUrl;
    }

    public function setBoardUrl(?string $boardUrl): static
    {
        $this->boardUrl = $boardUrl;

        return $this;
    }

    public function getSiteName(): ?string
    {
        return $this->siteName;
    }

    public function setSiteName(?string $siteName): static
    {
        $this->siteName = $siteName;

        return $this;
    }

    public function getSiteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(?string $siteUrl): static
    {
        $this->siteUrl = $siteUrl;

        return $this;
    }

    public function getRegistrationPolicy(): int
    {
        return $this->registrationPolicy;
    }

    public function setRegistrationPolicy(int $policy): static
    {
        $this->registrationPolicy = $policy;

        return $this;
    }

    public function getRegistrationEmail(): ?string
    {
        return $this->registrationEmail;
    }

    public function setRegistrationEmail(?string $email): static
    {
        $this->registrationEmail = $email;

        return $this;
    }

    public function isThreadLockingEnabled(): bool
    {
        return $this->threadLockingEnabled;
    }

    public function setThreadLockingEnabled(bool $enabled): static
    {
        $this->threadLockingEnabled = $enabled;

        return $this;
    }

    public function getSearchMinPower(): int
    {
        return $this->searchMinPower;
    }

    public function setSearchMinPower(int $power): static
    {
        $this->searchMinPower = $power;

        return $this;
    }

    public function getForumBanMinPower(): int
    {
        return $this->forumBanMinPower;
    }

    public function setForumBanMinPower(int $power): static
    {
        $this->forumBanMinPower = $power;

        return $this;
    }

    public function getDeletedUserAccount(): ?User
    {
        return $this->deletedUserAccount;
    }

    public function setDeletedUserAccount(?User $user): static
    {
        $this->deletedUserAccount = $user;

        return $this;
    }

    public function getSystemAccount(): ?User
    {
        return $this->systemAccount;
    }

    public function setSystemAccount(?User $user): static
    {
        $this->systemAccount = $user;

        return $this;
    }

    public function getDisciplinaryForum(): ?Forum
    {
        return $this->disciplinaryForum;
    }

    public function setDisciplinaryForum(?Forum $forum): static
    {
        $this->disciplinaryForum = $forum;

        return $this;
    }

    public function getTrashForum(): ?Forum
    {
        return $this->trashForum;
    }

    public function setTrashForum(?Forum $forum): static
    {
        $this->trashForum = $forum;

        return $this;
    }

    public function getMaxPostsPerThreadPerDay(): int
    {
        return $this->maxPostsPerThreadPerDay;
    }

    public function setMaxPostsPerThreadPerDay(int $max): static
    {
        $this->maxPostsPerThreadPerDay = $max;

        return $this;
    }

    public function getCustomTitlePostThreshold(): int
    {
        return $this->customTitlePostThreshold;
    }

    public function setCustomTitlePostThreshold(int $threshold): static
    {
        $this->customTitlePostThreshold = $threshold;

        return $this;
    }

    public function getCustomTitleAgePostThreshold(): int
    {
        return $this->customTitleAgePostThreshold;
    }

    public function setCustomTitleAgePostThreshold(int $threshold): static
    {
        $this->customTitleAgePostThreshold = $threshold;

        return $this;
    }

    public function getCustomTitleAgeDayThreshold(): int
    {
        return $this->customTitleAgeDayThreshold;
    }

    public function setCustomTitleAgeDayThreshold(int $threshold): static
    {
        $this->customTitleAgeDayThreshold = $threshold;

        return $this;
    }

    public function isPreventDoublePosting(): bool
    {
        return $this->preventDoublePosting;
    }

    public function setPreventDoublePosting(bool $prevent): static
    {
        $this->preventDoublePosting = $prevent;

        return $this;
    }

    public function isRequireEmailVerification(): bool
    {
        return $this->requireEmailVerification;
    }

    public function setRequireEmailVerification(bool $require): static
    {
        $this->requireEmailVerification = $require;

        return $this;
    }
}
