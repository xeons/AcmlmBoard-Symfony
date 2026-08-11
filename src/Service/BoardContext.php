<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BoardConfig;
use App\Entity\ColorScheme;
use App\Entity\ThreadLayout;
use App\Entity\User;
use App\Repository\BoardConfigRepository;
use App\Repository\ColorSchemeRepository;
use App\Repository\PrivateMessageRepository;
use App\Repository\ThreadLayoutRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The values the page chrome needs: board name, active scheme and layout, unread
 * message counts, the post-radar line.
 *
 * lib/layout.php computed all of this eagerly at the top of every request - around
 * twenty queries, including four COUNT(*)s for message badges and a full
 * updategb() rank recalculation - whether the page used any of it or not. Every
 * accessor here is memoised and only runs on first touch.
 */
final class BoardContext
{
    private ?BoardConfig $config = null;
    private ?ColorScheme $scheme = null;
    private ?ThreadLayout $layout = null;
    /** @var array{total: int, unread: int}|null */
    private ?array $messageCounts = null;
    /** @var array{total: int, unread: int}|null */
    private ?array $systemCounts = null;
    /** @var list<array{rival: User, difference: int, ahead: bool}>|null */
    private ?array $radar = null;
    /** @var array{users: int, threads: int, posts: int, postsToday: int, postsThisHour: int}|null */
    private ?array $totals = null;
    /** @var list<\App\Entity\Forum>|null */
    private ?array $forumJump = null;

    public function __construct(
        private readonly Security $security,
        private readonly BoardConfigRepository $configs,
        private readonly ColorSchemeRepository $schemes,
        private readonly ThreadLayoutRepository $layouts,
        private readonly PrivateMessageRepository $messages,
        private readonly \App\Repository\ForumRepository $forums,
        private readonly BoardStatsService $stats,
        private readonly PostRadar $postRadar,
        #[Autowire('%env(BOARD_NAME)%')]
        private readonly string $defaultBoardName,
    ) {
    }

    public function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function isLoggedIn(): bool
    {
        return null !== $this->getUser();
    }

    public function getConfig(): BoardConfig
    {
        return $this->config ??= $this->configs->get();
    }

    public function getName(): string
    {
        $name = $this->getConfig()->getBoardName();

        return '' !== $name ? $name : $this->defaultBoardName;
    }

    /** The viewer's chosen skin, falling back to the board default for guests. */
    public function getScheme(): ?ColorScheme
    {
        return $this->scheme ??= $this->getUser()?->getColorScheme() ?? $this->schemes->findDefault();
    }

    public function getLayout(): ?ThreadLayout
    {
        return $this->layout ??= $this->getUser()?->getThreadLayout() ?? $this->layouts->findDefault();
    }

    /**
     * Which quarter of the day the time-cycling scheme should render, so the
     * "dailycycle" palette shifts as the original's PHP colour interpolation did.
     *
     * Uses the member's own timezone, so the palette matches the light outside
     * their window rather than the server's.
     */
    public function getTimeOfDayClass(): string
    {
        $now = $this->getUser()?->toLocalTime(new \DateTimeImmutable())
            ?? new \DateTimeImmutable();

        return 'tod-'.intdiv((int) $now->format('G'), 6);
    }

    /** The viewer's timezone, or the board default for guests. */
    public function getTimezone(): \DateTimeZone
    {
        return $this->getUser()?->getTimezoneObject()
            ?? new \DateTimeZone($this->getConfig()->getDefaultTimezone());
    }

    public function getPostsPerPage(): int
    {
        return $this->getUser()?->getPostsPerPage() ?? 20;
    }

    public function getThreadsPerPage(): int
    {
        return $this->getUser()?->getThreadsPerPage() ?? 50;
    }

    /** @return array{total: int, unread: int} */
    public function getMessages(): array
    {
        if (null !== $this->messageCounts) {
            return $this->messageCounts;
        }

        $user = $this->getUser();
        if (null === $user) {
            return $this->messageCounts = ['total' => 0, 'unread' => 0];
        }

        return $this->messageCounts = [
            'total' => $this->messages->countReceived($user),
            'unread' => $this->messages->countUnread($user),
        ];
    }

    /** @return array{total: int, unread: int} */
    public function getSystemMessages(): array
    {
        if (null !== $this->systemCounts) {
            return $this->systemCounts;
        }

        $user = $this->getUser();
        if (null === $user) {
            return $this->systemCounts = ['total' => 0, 'unread' => 0];
        }

        return $this->systemCounts = [
            'total' => $this->messages->countReceived($user, system: true),
            'unread' => $this->messages->countUnread($user, system: true),
        ];
    }

    public function hasUnread(): bool
    {
        return $this->getMessages()['unread'] > 0 || $this->getSystemMessages()['unread'] > 0;
    }

    /** @return list<array{rival: User, difference: int, ahead: bool}> */
    public function getPostRadar(): array
    {
        if (null !== $this->radar) {
            return $this->radar;
        }

        $user = $this->getUser();

        return $this->radar = null === $user ? [] : $this->postRadar->compare($user);
    }

    /** @return array{users: int, threads: int, posts: int, postsToday: int, postsThisHour: int} */
    public function getTotals(): array
    {
        return $this->totals ??= $this->stats->totals();
    }

    public function getPageViews(): int
    {
        return $this->stats->get()->getPageViews();
    }

    /**
     * Forums for the jump menu at the foot of every listing, scoped to what the
     * viewer may read.
     *
     * @return list<\App\Entity\Forum>
     */
    public function getForumJumpList(): array
    {
        return $this->forumJump ??= $this->forums->findForJumpMenu($this->getUser()?->effectivePower() ?? 0);
    }

    public function canSearch(): bool
    {
        $min = $this->getConfig()->getSearchMinPower();

        return ($this->getUser()?->effectivePower() ?? 0) >= $min;
    }

    public function isStaff(): bool
    {
        return $this->getUser()?->isStaff() ?? false;
    }

    public function isAdmin(): bool
    {
        return $this->getUser()?->isAdmin() ?? false;
    }
}
